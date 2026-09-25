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
FACTORY_V1_WORKFLOW_RE = re.compile(
    r"^pl0n3r/factory/\.github/workflows/[A-Za-z0-9._-]+\.yml@v1$"
)
USES_RE = re.compile(r"^\s*(?:-\s*)?uses:\s*([^@\s]+)@([^\s#]+)")
JOB_RE = re.compile(r"^\s{2}([A-Za-z0-9_-]+):\s*$")
STEP_START_RE = re.compile(r"^(\s*)-\s+(?:name:|uses:|run:)")
WRITE_ALL_RE = re.compile(
    r"(?m)^\s*permissions:\s*write-all(?:\s*#.*)?$"
)


def active_yaml_lines(text: str) -> list[str]:
    """Ignora comentarios completos antes de evaluar estructura del workflow."""
    return [
        line
        for line in text.splitlines()
        if not line.lstrip().startswith("#")
    ]


def job_blocks(text: str) -> dict[str, str]:
    """Extrae jobs reales por indentación sin aceptar comentarios como config."""
    lines = active_yaml_lines(text)
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


def job_value(block: str, key: str) -> str | None:
    """Lee una clave declarada directamente en jobs.<id>, no texto anidado."""
    prefix = f"    {key}:"
    for line in block.splitlines():
        if not line.startswith(prefix):
            continue
        return line[len(prefix):].strip().split(" #", 1)[0].strip()
    return None


def workflow_section_value(text: str, section: str, key: str) -> str | None:
    """Lee una clave directa de una sección top-level simple."""
    lines = active_yaml_lines(text)
    section_line = f"{section}:"
    inside = False
    prefix = f"  {key}:"
    for line in lines:
        if not inside:
            if line == section_line:
                inside = True
            continue
        if line and not line.startswith(" "):
            break
        if line.startswith(prefix):
            return line[len(prefix):].strip().split(" #", 1)[0].strip()
    return None


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

def external_workflow_finding(
    path: Path, job_name: str, reference: str
) -> str | None:
    """Valida el pin de un reusable workflow externo."""
    if FACTORY_V1_WORKFLOW_RE.fullmatch(reference):
        return None
    if reference.startswith("$/"):
        if (
            not reference.startswith("$/.github/workflows/")
            or not reference.endswith(".yml")
            or "@" in reference
        ):
            return (
                f"{path}: job '{job_name}' usa workflow local inválido: "
                f"{reference}."
            )
        return None
    if "@" not in reference:
        return f"{path}: job '{job_name}' usa workflow externo sin SHA fijo: {reference}."
    _, ref = reference.rsplit("@", 1)
    if PIN_RE.fullmatch(ref):
        return None
    return (
        f"{path}: job '{job_name}' usa workflow externo sin SHA de "
        f"40 caracteres: {reference}."
    )


def audit_job_workflow_uses(path: Path, text: str) -> list[str]:
    """Audita referencias reusable-workflow declaradas a nivel de job."""
    findings: list[str] = []
    for name, block in job_blocks(text).items():
        reference = job_value(block, "uses")
        if reference is None:
            continue
        if reference.startswith("./"):
            if (
                not reference.startswith("./.github/workflows/")
                or not reference.endswith(".yml")
                or "@" in reference
            ):
                findings.append(
                    f"{path}: job '{name}' usa workflow local inválido: {reference}."
                )
            continue
        finding = external_workflow_finding(path, name, reference)
        if finding is not None:
            findings.append(finding)
    return findings


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
    findings.extend(audit_job_workflow_uses(path, text))
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
    active_text = "\n".join(active_yaml_lines(text))
    findings: list[str] = []

    if "pull_request_target:" in active_text:
        findings.append(f"{path}: pull_request_target no está permitido.")
    if "continue-on-error: true" in active_text:
        findings.append(
            f"{path}: continue-on-error: true puede ocultar fallos deterministas."
        )
    if WRITE_ALL_RE.search(active_text):
        findings.append(f"{path}: permissions: write-all no está permitido.")

    for name, block in job_blocks(text).items():
        if job_value(block, "runs-on") is not None and job_value(
            block, "timeout-minutes"
        ) is None:
            findings.append(f"{path}: job '{name}' no tiene timeout-minutes.")

    findings.extend(audit_action_pins(path, text))
    return findings


def job_commands(block: str) -> list[str]:
    """Devuelve comandos reales de los steps de un job."""
    commands: list[str] = []
    for step in step_blocks(block):
        commands.extend(logical_shell_commands(step))
    return commands


def active_shell_lines(step: list[str]) -> list[str]:
    """Devuelve líneas shell ejecutables, sin blancos ni comentarios completos."""
    return [
        line.strip()
        for line in run_content(step)
        if line.strip() and not line.lstrip().startswith("#")
    ]


def valid_release_transition_flow(step: list[str]) -> bool:
    """Valida el flujo canónico desde clasificador hasta GITHUB_OUTPUT."""
    lines = active_shell_lines(step)
    assignment_index = next(
        (
            index
            for index, line in enumerate(lines)
            if line.startswith('requiere="$(')
        ),
        None,
    )
    if assignment_index is None:
        return False

    control_prefixes = ("if ", "for ", "while ", "until ", "case ", "select ")
    if any(
        line.startswith(control_prefixes)
        for line in lines[:assignment_index]
    ):
        return False

    end_index = next(
        (
            index
            for index in range(assignment_index + 1, len(lines))
            if lines[index] == ')"'
        ),
        None,
    )
    if end_index is None:
        return False

    assignment = "\n".join(lines[assignment_index:end_index + 1])
    if (
        "scripts/ci_change_classifier.py" not in assignment
        or "--format json" not in assignment
        or '["transicion_release"]' not in assignment
    ):
        return False

    publish_index = end_index + 1
    if publish_index >= len(lines):
        return False
    if re.fullmatch(
        r'echo\s+"requerida=\$requiere"\s*>>\s*"\$GITHUB_OUTPUT"',
        lines[publish_index],
    ) is None:
        return False

    if any(
        line.startswith("requiere=")
        for line in lines[end_index + 1:]
        if line != lines[publish_index]
    ):
        return False

    return True


def audit_release_observer(path: Path) -> list[str]:
    """Exige que observar-release use de forma efectiva el detector canónico."""
    text = path.read_text(encoding="utf-8")
    if any(valid_release_transition_flow(step) for step in step_blocks(text)):
        return []
    return [
        f"{path}: observar-release debe extraer transicion_release del "
        "ci_change_classifier.py, publicarlo en GITHUB_OUTPUT y no "
        "sobrescribir la decisión después."
    ]


def audit_main_ci(path: Path) -> list[str]:
    text = path.read_text(encoding="utf-8")
    jobs = job_blocks(text)
    findings: list[str] = []

    if workflow_section_value(text, "concurrency", "cancel-in-progress") != "true":
        findings.append(f"{path}: falta cancelación de runs obsoletos.")

    command_text = "\n".join(
        command
        for block in jobs.values()
        for command in job_commands(block)
    )
    required_commands = {
        "scripts/ci_change_classifier.py": "clasificación testeable de cambios",
        "scripts/ci_retry.py": "reintentos seguros",
        "scripts/ci_self_audit.py": "autoauditoría del CI",
    }
    for token, description in required_commands.items():
        if token not in command_text:
            findings.append(f"{path}: falta {description}.")

    preflight = jobs.get("preflight", "")
    required_preflight_tokens = {
        "steps.cambios.outputs.modo": "output de modo del clasificador",
        "steps.cambios.outputs.motivo": "output de motivo del clasificador",
        "steps.cambios.outputs.categorias": "output de categorías del clasificador",
        "steps.cambios.outputs.frontend": "output del gate frontend",
        "| Gate | Decisión | Razón |": "resumen explicativo de gates",
    }
    for token, description in required_preflight_tokens.items():
        if token not in preflight:
            findings.append(f"{path}: falta {description}.")

    required_job_conditions = {
        "pruebas-base": (
            "needs.preflight.outputs.pruebas_base == 'true'",
            "gate base selectivo",
        ),
        "frontend": (
            "needs.preflight.outputs.frontend == 'true'",
            "frontend selectivo",
        ),
        "backend-php": (
            "needs.preflight.outputs.backend == 'true'",
            "backend selectivo",
        ),
        "e2e": (
            "needs.preflight.outputs.e2e == 'true'",
            "E2E selectivo",
        ),
    }
    for job_name, (condition, description) in required_job_conditions.items():
        block = jobs.get(job_name)
        if block is None or condition not in (job_value(block, "if") or ""):
            findings.append(f"{path}: falta {description}.")

    final_commands = "\n".join(job_commands(jobs.get("validar", "")))
    required_final = {
        'case "$PRUEBAS_BASE" in': "aceptación explícita de gate base omitido",
        'case "$FRONTEND" in': "aceptación explícita de frontend omitido",
        'case "$BACKEND_PHP" in': "aceptación explícita de backend omitido",
        'case "$E2E" in': "aceptación explícita de E2E omitido",
    }
    for token, description in required_final.items():
        if token not in final_commands:
            findings.append(f"{path}: falta {description}.")

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

    release_observer = root / WORKFLOW_RELATIVE_DIR / "observar-release.yml"
    if not release_observer.is_file():
        findings.append("Falta .github/workflows/observar-release.yml.")
    else:
        findings.extend(audit_release_observer(release_observer))

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
