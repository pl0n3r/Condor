#!/usr/bin/env python3
"""Audita invariantes de seguridad, resiliencia y eficiencia del CI de Condor."""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
WORKFLOW_RELATIVE_DIR = Path(".github") / "workflows"
WORKFLOW_DIR = ROOT / WORKFLOW_RELATIVE_DIR
PIN_RE = re.compile(r"^[0-9a-f]{40}$")
USES_RE = re.compile(r"^\s*(?:-\s*)?uses:\s*([^@\s]+)@([^\s#]+)")
JOB_RE = re.compile(r"^\s{2}([A-Za-z0-9_-]+):\s*$")
STEP_START_RE = re.compile(r"^(\s*)-\s+(?:name:|uses:|run:)")
WRITE_ALL_RE = re.compile(
    r"(?m)^\s*permissions:\s*write-all(?:\s*#.*)?$"
)


def job_blocks(text: str) -> dict[str, str]:
    """Extrae bloques de jobs mediante la indentación estable de Actions YAML."""
    lines = text.splitlines()
    inside = False
    current: str | None = None
    buffers: dict[str, list[str]] = {}

    for line in lines:
        if not inside:
            if line.strip() == "jobs:" and not line.startswith(" "):
                inside = True
            continue

        if line and not line.startswith(" "):
            break

        match = JOB_RE.match(line)
        if match:
            current = match.group(1)
            buffers[current] = [line]
            continue

        if current is not None:
            buffers[current].append(line)

    return {name: "\n".join(lines_) for name, lines_ in buffers.items()}


def step_blocks(text: str) -> list[list[str]]:
    """Extrae steps completos para que los controles no crucen entre steps."""
    lines = text.splitlines()
    starts: list[tuple[int, int]] = []
    for index, line in enumerate(lines):
        match = STEP_START_RE.match(line)
        if match:
            starts.append((index, len(match.group(1))))

    blocks: list[list[str]] = []
    for position, (start, indent) in enumerate(starts):
        end = len(lines)
        for next_start, next_indent in starts[position + 1:]:
            if next_indent == indent:
                end = next_start
                break
            if next_indent < indent:
                end = next_start
                break
        blocks.append(lines[start:end])
    return blocks


def checkout_has_safe_credentials(step: list[str]) -> bool:
    """Exige persist-credentials=false dentro de with: del mismo checkout."""
    with_indent: int | None = None
    for line in step:
        stripped = line.lstrip()
        indent = len(line) - len(stripped)

        if stripped.startswith("with:"):
            with_indent = indent
            continue

        if with_indent is None:
            continue
        if stripped and not stripped.startswith("#") and indent <= with_indent:
            with_indent = None
            continue

        if (
            with_indent is not None
            and indent > with_indent
            and re.match(
                r"^persist-credentials:\s*false(?:\s*#.*)?$",
                stripped,
            )
        ):
            return True
    return False


def run_directive(line: str) -> str | None:
    """Devuelve el payload de una directiva YAML run: sin regex ambigua."""
    stripped = line.lstrip()
    if stripped.startswith("- "):
        stripped = stripped[2:].lstrip()
    if not stripped.startswith("run:"):
        return None
    return stripped[4:].strip()


def indented_run_lines(lines: list[str], run_indent: int) -> list[str]:
    """Extrae solo las líneas pertenecientes al bloque run actual."""
    content: list[str] = []
    for line in lines:
        stripped = line.strip()
        indent = len(line) - len(line.lstrip())
        if stripped and indent <= run_indent and not stripped.startswith("#"):
            break
        if indent > run_indent:
            content.append(line)
    return content


def run_content(step: list[str]) -> list[str]:
    """Localiza la única directiva run de un step y devuelve su contenido."""
    for index, line in enumerate(step):
        payload = run_directive(line)
        if payload is None:
            continue
        if payload not in {"", "|", ">"}:
            return [payload]
        run_indent = len(line) - len(line.lstrip())
        return indented_run_lines(step[index + 1:], run_indent)
    return []


def logical_shell_commands(step: list[str]) -> list[str]:
    """Une continuaciones de shell dentro de un único step run."""
    commands: list[str] = []
    buffer: list[str] = []

    for line in run_content(step):
        piece = line.strip()
        if not piece or piece.startswith("#"):
            continue
        if piece.endswith("\\"):
            buffer.append(piece[:-1].rstrip())
            continue

        buffer.append(piece)
        commands.append(" ".join(buffer))
        buffer.clear()

    if buffer:
        commands.append(" ".join(buffer))
    return commands

def audit_action_pins(path: Path, text: str) -> list[str]:
    findings: list[str] = []
    for step in step_blocks(text):
        action_line = next(
            (line for line in step if USES_RE.match(line)),
            None,
        )
        if action_line is None:
            continue

        match = USES_RE.match(action_line)
        assert match is not None
        action, ref = match.groups()
        if action.startswith("./"):
            continue
        if not PIN_RE.fullmatch(ref):
            findings.append(
                f"{path}: action no fijada a SHA de 40 caracteres: {action}@{ref}."
            )

        if action == "actions/checkout" and not checkout_has_safe_credentials(step):
            findings.append(
                f"{path}: checkout debe usar persist-credentials: false "
                "dentro de su propio bloque with."
            )
    return findings


def audit_retry_wrappers(path: Path, text: str) -> list[str]:
    """Comprueba cada instalación externa en su comando shell concreto."""
    findings: list[str] = []
    targets = ("composer install", "npm ci", "playwright install ffmpeg")

    for step in step_blocks(text):
        for command in logical_shell_commands(step):
            for target in targets:
                if target not in command:
                    continue
                if "scripts/ci_retry.py" not in command:
                    findings.append(
                        f"{path}: '{target}' debe ejecutarse mediante "
                        "scripts/ci_retry.py en el mismo comando."
                    )
    return findings


def audit_workflow(path: Path) -> list[str]:
    text = path.read_text(encoding="utf-8")
    findings: list[str] = []

    if "pull_request_target:" in text:
        findings.append(f"{path}: pull_request_target no está permitido.")
    if "continue-on-error: true" in text:
        findings.append(
            f"{path}: continue-on-error: true puede ocultar fallos deterministas."
        )
    if WRITE_ALL_RE.search(text):
        findings.append(f"{path}: permissions: write-all no está permitido.")

    for name, block in job_blocks(text).items():
        if "runs-on:" in block and "timeout-minutes:" not in block:
            findings.append(f"{path}: job '{name}' no tiene timeout-minutes.")

    findings.extend(audit_action_pins(path, text))
    return findings


def audit_main_ci(path: Path) -> list[str]:
    text = path.read_text(encoding="utf-8")
    required = {
        "cancel-in-progress: true": "cancelación de runs obsoletos",
        "scripts/ci_change_classifier.py": "clasificación testeable de cambios",
        "scripts/ci_retry.py": "reintentos seguros",
        "scripts/ci_self_audit.py": "autoauditoría del CI",
        "needs.preflight.outputs.pruebas_base == 'true'": "gate base selectivo",
        "needs.preflight.outputs.backend == 'true'": "backend selectivo",
        "needs.preflight.outputs.e2e == 'true'": "E2E selectivo",
        'case "$PRUEBAS_BASE" in': "aceptación explícita de gate base omitido",
        'case "$BACKEND_PHP" in': "aceptación explícita de backend omitido",
    }

    findings = [
        f"{path}: falta {description}."
        for token, description in required.items()
        if token not in text
    ]
    findings.extend(audit_retry_wrappers(path, text))
    return findings


def audit_repository(root: Path = ROOT) -> list[str]:
    workflows = sorted((root / WORKFLOW_RELATIVE_DIR).glob("*.yml"))
    findings: list[str] = []
    if not workflows:
        return ["No se encontraron workflows para auditar."]

    for path in workflows:
        findings.extend(audit_workflow(path))

    main_ci = root / WORKFLOW_RELATIVE_DIR / "ci.yml"
    if not main_ci.is_file():
        findings.append("Falta .github/workflows/ci.yml.")
    else:
        findings.extend(audit_main_ci(main_ci))

    return findings


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()

    findings = audit_repository()
    workflows = len(list(WORKFLOW_DIR.glob("*.yml")))

    if args.json:
        print(json.dumps(
            {"workflows": workflows, "findings": findings},
            indent=2,
            sort_keys=True,
        ))
    elif findings:
        print("Autoauditoría CI fallida:", file=sys.stderr)
        for finding in findings:
            print(f"- {finding}", file=sys.stderr)
    else:
        print(
            f"CI autoauditado correctamente: {workflows} workflows sin "
            "invariantes incumplidas."
        )

    return 1 if findings else 0


if __name__ == "__main__":
    raise SystemExit(main())
