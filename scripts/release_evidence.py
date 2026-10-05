#!/usr/bin/env python3
"""Genera y consolida evidencia reproducible de release sin mutar producción."""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any, Iterable

SCRIPT_DIR = Path(__file__).resolve().parent
ROOT = SCRIPT_DIR.parent
if str(SCRIPT_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPT_DIR))

from ci_change_classifier import classify, script_requires_release_transition

SCHEMA = "condor.release-evidence.v1"
CANONICAL_VERSION_FILE = ROOT / "config" / "version.php"
SHA_PATTERN = re.compile(r"[0-9a-f]{40}\Z", re.ASCII)
VERSION_PATTERN = re.compile(r"\d+\.\d+\.\d+\Z", re.ASCII)
VERSION_ASSIGNMENT = re.compile(
    r"['\"]version['\"]\s*=>\s*['\"](\d+\.\d+\.\d+)['\"]",
    re.ASCII,
)
PUBLIC_CHECKS = (
    "health",
    "home",
    "admin_login",
    "css_publico",
    "css_admin",
    "js_admin",
)
STOREFRONT_CHECKS = ("storefront", "slug_desconocido")


def public_checks_for_version(version: str) -> list[str]:
    checks = list(PUBLIC_CHECKS)
    if tuple(map(int, version.split("."))) >= (0, 1, 13):
        checks.extend(STOREFRONT_CHECKS)
    return checks
CHECK_IDS = (
    "migraciones",
    "roles",
    "comandos",
    "configuracion",
    "cache",
)
OBSERVATION_STATES = {
    "NO_OBSERVADO",
    "DEPLOY_OBSERVED",
    "VALIDATED_IN_PRODUCTION",
}

DEVELOPMENT_PHASE = "construccion"
DEVELOPMENT_AUTO_TRANSITIONS = frozenset({"migraciones", "comandos", "cache"})
DEVELOPMENT_SAFE_COMMAND_SCRIPTS = frozenset({
    "scripts/release_evidence.py",
    "scripts/d043_pending_releases.py",
})
DEVELOPMENT_AUTO_MARKER = "condor-d043-dev-auto"

PHP_NOWDOC_ADD_SQL = re.compile(
    r"""\$this->addSql\(\s*<<<'(?P<label>[A-Za-z_][A-Za-z0-9_]*)'\r?\n(?P<sql>.*?)\r?\n(?P=label)\s*\)\s*;""",
    re.DOTALL,
)
PHP_SINGLE_QUOTED_ADD_SQL = re.compile(
    r"""\$this->addSql\(\s*'(?P<sql>(?:\\.|[^'\\])*)'\s*\)\s*;""",
    re.DOTALL,
)
PHP_UP_METHOD = re.compile(
    r"public\s+function\s+up\s*\([^)]*\)\s*(?::\s*void)?\s*\{",
    re.IGNORECASE,
)
PHP_DOWN_METHOD = re.compile(r"public\s+function\s+down\s*\(", re.IGNORECASE)
SQL_CREATE_TABLE = re.compile(
    r"^CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+([^\s(]+)", re.IGNORECASE
)
SQL_CREATE_INDEX = re.compile(
    r"^CREATE\s+(?:UNIQUE\s+)?INDEX\s+[^\s]+\s+ON\s+[^\s(]+", re.IGNORECASE
)
SQL_ALTER_ADD_COLUMN = re.compile(
    r"^ALTER\s+TABLE\s+([^\s]+)\s+ADD\s+(?:COLUMN\s+)?([^\s]+)\s+(.+)$",
    re.IGNORECASE | re.DOTALL,
)
SQL_ALTER_ADD_CONSTRAINT = re.compile(
    r"^ALTER\s+TABLE\s+([^\s]+)\s+ADD\s+CONSTRAINT\s+.+$",
    re.IGNORECASE | re.DOTALL,
)
SQL_FORBIDDEN = re.compile(
    r"\b(?:DROP|TRUNCATE|RENAME|UPDATE|INSERT|REPLACE|CALL)\b|\bDELETE\s+FROM\b",
    re.IGNORECASE,
)
SQL_SAFE_DEFAULT = re.compile(
    r"""\bDEFAULT\s+(?:NULL|TRUE|FALSE|-?\d+(?:\.\d+)?|'(?:''|[^'])*')(?=\s|,|$)""",
    re.IGNORECASE,
)


class EvidenceError(ValueError):
    """Error determinista presentable sin datos sensibles."""


def normalized_paths(paths: Iterable[str]) -> list[str]:
    """Normaliza el diff sin alterar nombres válidos de archivos."""
    return sorted({path.strip() for path in paths if path.strip()})


def read_version(path: Path) -> str:
    """Lee una fuente de versión controlada por el repositorio."""
    try:
        text = path.read_text(encoding="utf-8")
    except OSError as error:
        raise EvidenceError("No se pudo leer la fuente canónica de versión.") from error

    matches = VERSION_ASSIGNMENT.findall(text)
    if len(matches) != 1 or VERSION_PATTERN.fullmatch(matches[0]) is None:
        raise EvidenceError("La fuente canónica no contiene una única versión X.Y.Z.")
    return matches[0]


def transition_requirements(paths: list[str], required: bool) -> dict[str, bool]:
    """Deriva un checklist operativo sin ejecutar ninguna transición."""
    migrations = any(path.startswith("migrations/") for path in paths)
    roles = any(
        path == "config/packages/security.yaml"
        or path.startswith(
            (
                "src/Domain/Identity/",
                "src/Application/Identity/",
                "src/Infrastructure/Security/",
            )
        )
        for path in paths
    )
    commands = any(
        path == "bin/console"
        or path.startswith("src/Console/")
        or (path.startswith("scripts/") and script_requires_release_transition(path))
        for path in paths
    )
    configuration = any(
        (path.startswith("config/") and path != "config/version.php")
        or path in {".env.example", ".htaccess", "public/index.php"}
        for path in paths
    )

    return {
        "migraciones": migrations,
        "roles": roles,
        "comandos": commands,
        "configuracion": configuration,
        "cache": required,
    }


def build_manifest_explicit(
    *,
    version: str,
    sha: str,
    changed_paths: Iterable[str],
) -> dict[str, Any]:
    """Construye un manifiesto desde identidad exacta ya validada por el caller."""
    if VERSION_PATTERN.fullmatch(version) is None:
        raise EvidenceError("La versión de release debe tener formato X.Y.Z.")
    if SHA_PATTERN.fullmatch(sha) is None:
        raise EvidenceError("El SHA de release debe tener 40 hexadecimales minúsculos.")

    paths = normalized_paths(changed_paths)
    selection = classify(paths, "pull_request")
    requirements = transition_requirements(paths, selection.transicion_release)

    return {
        "schema": SCHEMA,
        "version": version,
        "sha": sha,
        "version_source": "config/version.php",
        "change_count": len(paths),
        "categories": selection.categorias,
        "selection_mode": selection.modo,
        "selection_reason": selection.motivo,
        "public_checks": public_checks_for_version(version),
        "transition": {
            "required": selection.transicion_release,
            "checks": [
                {"id": check_id, "required": requirements[check_id]}
                for check_id in CHECK_IDS
            ],
        },
    }


def build_manifest(
    *,
    version_file: Path,
    sha: str,
    changed_paths: Iterable[str],
    expected_version: str | None = None,
) -> dict[str, Any]:
    """Construye la identidad y el checklist verificable de una release."""
    version = read_version(version_file)
    if expected_version is not None and version != expected_version:
        raise EvidenceError("La versión solicitada no coincide con config/version.php.")

    return build_manifest_explicit(
        version=version,
        sha=sha,
        changed_paths=changed_paths,
    )


def validated_transition_check(item: object) -> tuple[object, bool]:
    """Valida una entrada individual del checklist y devuelve sus campos canónicos."""
    if not isinstance(item, dict):
        raise EvidenceError("Cada comprobación de transición debe ser un objeto.")
    required = item.get("required")
    if not isinstance(required, bool):
        raise EvidenceError("Cada checks[*].required debe ser booleano.")
    return item.get("id"), required


def validate_transition(transition: object) -> None:
    """Valida forma, tipos y coherencia interna del checklist de transición."""
    if not isinstance(transition, dict):
        raise EvidenceError("El manifiesto no contiene checklist de transición.")

    transition_required = transition.get("required")
    if not isinstance(transition_required, bool):
        raise EvidenceError("transition.required debe ser booleano.")

    checks = transition.get("checks")
    if not isinstance(checks, list) or len(checks) != len(CHECK_IDS):
        raise EvidenceError("El checklist de transición es inválido.")

    normalized = [validated_transition_check(item) for item in checks]
    ids = [check_id for check_id, _ in normalized]
    required_flags = [required for _, required in normalized]

    if ids != list(CHECK_IDS):
        raise EvidenceError("El checklist de transición no coincide con el contrato.")
    if transition_required != any(required_flags):
        raise EvidenceError("transition.required no coincide con el checklist.")
    if transition_required and not required_flags[-1]:
        raise EvidenceError("Una transición requerida debe exigir verificación de caché.")


def validated_release_identity(value: object) -> tuple[str, str]:
    """Valida una identidad mínima de release sin aceptar campos implícitos."""
    if not isinstance(value, dict):
        raise EvidenceError("La identidad de release debe ser un objeto.")

    version = value.get("version")
    sha = value.get("sha")
    if not isinstance(version, str) or VERSION_PATTERN.fullmatch(version) is None:
        raise EvidenceError("La identidad de release contiene una versión inválida.")
    if not isinstance(sha, str) or SHA_PATTERN.fullmatch(sha) is None:
        raise EvidenceError("La identidad de release contiene un SHA inválido.")
    return version, sha


def version_key(version: str) -> tuple[int, int, int]:
    """Convierte X.Y.Z en una clave comparable sin inferir saltos válidos."""
    major, minor, patch = map(int, version.split("."))
    return major, minor, patch


def validate_covered_releases(manifest: dict[str, Any]) -> None:
    """Valida cobertura explícita de releases anteriores sin inferirla por versión."""
    covered = manifest.get("covered_releases", [])
    if not isinstance(covered, list):
        raise EvidenceError("covered_releases debe ser una lista.")

    current_version, current_sha = validated_release_identity(manifest)
    current_key = version_key(current_version)
    seen_versions: dict[str, str] = {current_version: current_sha}
    seen_shas: dict[str, str] = {current_sha: current_version}
    previous_key: tuple[int, int, int] | None = None

    for item in covered:
        version, sha = validated_release_identity(item)
        key = version_key(version)
        if key >= current_key:
            raise EvidenceError(
                "Las releases cubiertas deben ser anteriores a la release actual."
            )

        known_sha = seen_versions.get(version)
        if known_sha is not None:
            if known_sha != sha:
                raise EvidenceError(
                    "Una versión cubierta no puede apuntar a dos SHAs distintos."
                )
            raise EvidenceError("covered_releases contiene una identidad duplicada.")

        known_version = seen_shas.get(sha)
        if known_version is not None:
            raise EvidenceError(
                "Un SHA cubierto no puede pertenecer a dos versiones distintas."
            )

        if previous_key is not None and key <= previous_key:
            raise EvidenceError("covered_releases no está en orden canónico.")

        seen_versions[version] = sha
        seen_shas[sha] = version
        previous_key = key


def validate_manifest(manifest: dict[str, Any]) -> None:
    """Valida identidad y checklist antes de permitir cualquier promoción de estado."""
    if manifest.get("schema") != SCHEMA:
        raise EvidenceError("El manifiesto de release usa un schema no soportado.")

    version = manifest.get("version")
    if not isinstance(version, str) or VERSION_PATTERN.fullmatch(version) is None:
        raise EvidenceError("El manifiesto contiene una versión inválida.")

    sha = manifest.get("sha")
    if not isinstance(sha, str) or SHA_PATTERN.fullmatch(sha) is None:
        raise EvidenceError("El manifiesto contiene un SHA inválido.")

    if manifest.get("public_checks") != public_checks_for_version(version):
        raise EvidenceError("El manifiesto no contiene el contrato público esperado.")

    validate_transition(manifest.get("transition"))
    validate_covered_releases(manifest)


def accumulate_pending_manifests(
    current_manifest: dict[str, Any],
    pending_manifests: object,
) -> dict[str, Any]:
    """Acumula checks pendientes explícitos sin mutar los manifiestos de entrada."""
    validate_manifest(current_manifest)
    if not isinstance(pending_manifests, list):
        raise EvidenceError("pending_manifests debe ser una lista.")

    current_version, current_sha = validated_release_identity(current_manifest)
    current_key = version_key(current_version)
    requirements = {
        item["id"]: item["required"] is True
        for item in current_manifest["transition"]["checks"]
    }
    coverage: dict[tuple[str, str], dict[str, str]] = {}
    version_to_sha: dict[str, str] = {}
    sha_to_version: dict[str, str] = {}

    def add_coverage(version: str, sha: str) -> None:
        identity = (version, sha)
        if identity == (current_version, current_sha):
            raise EvidenceError(
                "La release actual no puede aparecer como release pendiente cubierta."
            )
        if version_key(version) >= current_key:
            raise EvidenceError(
                "Una release pendiente debe ser anterior a la release actual."
            )

        known_sha = version_to_sha.get(version)
        if known_sha is not None and known_sha != sha:
            raise EvidenceError(
                "Una versión pendiente no puede apuntar a dos SHAs distintos."
            )
        known_version = sha_to_version.get(sha)
        if known_version is not None and known_version != version:
            raise EvidenceError(
                "Un SHA pendiente no puede pertenecer a dos versiones distintas."
            )
        if identity in coverage:
            return

        version_to_sha[version] = sha
        sha_to_version[sha] = version
        coverage[identity] = {"version": version, "sha": sha}

    for item in current_manifest.get("covered_releases", []):
        version, sha = validated_release_identity(item)
        add_coverage(version, sha)

    for pending in pending_manifests:
        if not isinstance(pending, dict):
            raise EvidenceError("Cada manifiesto pendiente debe ser un objeto.")
        validate_manifest(pending)

        version, sha = validated_release_identity(pending)
        add_coverage(version, sha)
        for item in pending.get("covered_releases", []):
            covered_version, covered_sha = validated_release_identity(item)
            add_coverage(covered_version, covered_sha)

        for item in pending["transition"]["checks"]:
            check_id = item["id"]
            requirements[check_id] = (
                requirements[check_id] or item["required"] is True
            )

    result = dict(current_manifest)
    result["covered_releases"] = sorted(
        coverage.values(),
        key=lambda item: (version_key(item["version"]), item["sha"]),
    )
    result["transition"] = {
        "required": any(requirements.values()),
        "checks": [
            {"id": check_id, "required": requirements[check_id]}
            for check_id in CHECK_IDS
        ],
    }
    validate_manifest(result)
    return result


def public_evidence(
    observation: dict[str, Any], public_checks: list[str],
) -> dict[str, dict[str, Any]]:
    """Normaliza el contrato de smoke y marca comprobaciones ausentes."""
    raw_checks = observation.get("comprobaciones")
    observation_checks = raw_checks if isinstance(raw_checks, dict) else {}
    public: dict[str, dict[str, Any]] = {}

    for check_id in public_checks:
        raw = observation_checks.get(check_id)
        if not isinstance(raw, dict):
            public[check_id] = {
                "ok": False,
                "clase": "funcional",
                "detalle": "No se registró esta comprobación.",
            }
            continue

        public[check_id] = {
            "ok": raw.get("ok") is True,
            "clase": raw.get("clase", "desconocido"),
            "detalle": str(raw.get("detalle", "Sin evidencia.")),
            **(
                {"intento": raw["intento"]}
                if isinstance(raw.get("intento"), int)
                else {}
            ),
        }

    return public


def transition_evidence(
    manifest: dict[str, Any],
    verified: set[str],
) -> tuple[dict[str, dict[str, bool]], list[str]]:
    """Evalúa únicamente el checklist requerido por el manifiesto."""
    checks: dict[str, dict[str, bool]] = {}
    pending: list[str] = []

    for item in manifest["transition"]["checks"]:
        check_id = item["id"]
        required = item.get("required") is True
        is_verified = check_id in verified
        checks[check_id] = {
            "required": required,
            "verified": is_verified,
            "ok": (not required) or is_verified,
        }
        if required and not is_verified:
            pending.append(check_id)

    return checks, pending


def release_state(
    *,
    same_identity: bool,
    observation_state: object,
    public: dict[str, dict[str, Any]],
    required_pending: list[str],
) -> str:
    """Mantiene separados identidad observada, deploy y validación."""
    if observation_state not in OBSERVATION_STATES:
        raise EvidenceError("La observación contiene un estado no soportado.")

    identity_observed = same_identity and public["health"]["ok"]
    if not identity_observed or observation_state == "NO_OBSERVADO":
        return "NO_OBSERVADO"
    if observation_state == "DEPLOY_OBSERVED":
        return "DEPLOY_OBSERVED"
    if all(item["ok"] for item in public.values()) and not required_pending:
        return "VALIDATED_IN_PRODUCTION"
    return "DEPLOY_OBSERVED"


def finalize(
    manifest: dict[str, Any],
    observation: dict[str, Any],
    verified: Iterable[str],
) -> dict[str, Any]:
    """Combina identidad, smoke y transición sin convertir deploy en validación implícita."""
    validate_manifest(manifest)
    verified_set = set(verified)
    if verified_set.difference(CHECK_IDS):
        raise EvidenceError("Se intentó verificar una comprobación de transición desconocida.")

    same_identity = (
        observation.get("version_esperada") == manifest["version"]
        and observation.get("sha_esperado") == manifest["sha"]
    )
    public = public_evidence(observation, manifest["public_checks"])
    transition_checks, required_pending = transition_evidence(
        manifest,
        verified_set,
    )
    state = release_state(
        same_identity=same_identity,
        observation_state=observation.get("estado"),
        public=public,
        required_pending=required_pending,
    )

    return {
        "schema": SCHEMA,
        "estado": state,
        "version": manifest["version"],
        "sha": manifest["sha"],
        "identity_match": same_identity,
        "public_checks": public,
        "transition": {
            "required": manifest["transition"]["required"] is True,
            "checks": transition_checks,
            "pending": required_pending,
        },
    }

def migration_up_section(source: str) -> str | None:
    """Extrae up() sin interpretar PHP; cualquier forma inesperada falla cerrado."""
    up = PHP_UP_METHOD.search(source)
    if up is None:
        return None
    down = PHP_DOWN_METHOD.search(source, up.end())
    if down is None:
        return None
    return source[up.end():down.start()]


def php_static_add_sql_blocks(section: str) -> tuple[list[str], str | None]:
    """Acepta solo addSql() con string simple o nowdoc estático."""
    matches = [*PHP_NOWDOC_ADD_SQL.finditer(section), *PHP_SINGLE_QUOTED_ADD_SQL.finditer(section)]
    matches.sort(key=lambda item: item.start())
    if len(matches) != section.count("$this->addSql("):
        return [], "dynamic_or_unparsed_add_sql"

    blocks: list[str] = []
    cursor = 0
    residue: list[str] = []
    for match in matches:
        if match.start() < cursor:
            return [], "overlapping_add_sql"
        residue.append(section[cursor:match.start()])
        raw = match.group("sql")
        if match.re is PHP_SINGLE_QUOTED_ADD_SQL:
            raw = raw.replace("\\'", "'").replace("\\\\", "\\")
        blocks.append(raw)
        cursor = match.end()
    residue.append(section[cursor:])

    remainder = "".join(residue)
    remainder = re.sub(r"/\*.*?\*/", "", remainder, flags=re.DOTALL)
    remainder = re.sub(r"//[^\n]*|#[^\n]*", "", remainder)
    remainder = remainder.replace("}", "").strip()
    if remainder:
        return [], "non_add_sql_operation"
    if not blocks:
        return [], "no_static_add_sql"
    return blocks, None


def split_sql_statements(sql: str) -> list[str]:
    """Divide SQL estático respetando literales y backticks."""
    statements: list[str] = []
    current: list[str] = []
    quote: str | None = None
    escaped = False
    for char in sql:
        if quote is not None:
            current.append(char)
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = None
            continue
        if char in {"'", '"', "`"}:
            quote = char
            current.append(char)
            continue
        if char == ";":
            statement = "".join(current).strip()
            if statement:
                statements.append(statement)
            current = []
            continue
        current.append(char)
    if quote is not None:
        return []
    tail = "".join(current).strip()
    if tail:
        statements.append(tail)
    return statements


def sql_identifier(value: str) -> str:
    """Normaliza identificadores simples para comparar tablas creadas."""
    return value.strip().replace("`", "").lower()


def safe_add_column(definition: str) -> bool:
    """Permite columnas nullable o NOT NULL con default literal determinista."""
    upper = definition.upper()
    if any(token in upper for token in ("AUTO_INCREMENT", "GENERATED", " AS (")):
        return False
    nullable = re.search(r"\bNULL\b", definition, re.IGNORECASE) is not None
    not_null = re.search(r"\bNOT\s+NULL\b", definition, re.IGNORECASE) is not None
    return (nullable and not not_null) or SQL_SAFE_DEFAULT.search(definition) is not None


def classify_sql_statement(statement: str, created_tables: set[str]) -> tuple[bool, str]:
    """Clasifica una sentencia ascendente con allowlist aditiva estricta."""
    statement = re.sub(r"/\*.*?\*/", "", statement, flags=re.DOTALL)
    statement = re.sub(r"--[^\\n]*|#[^\\n]*", "", statement).strip()
    if not statement:
        return True, "empty"
    if SQL_FORBIDDEN.search(statement):
        return False, "destructive_keyword"

    create_table = SQL_CREATE_TABLE.match(statement)
    if create_table is not None:
        created_tables.add(sql_identifier(create_table.group(1)))
        return True, "create_table"
    if SQL_CREATE_INDEX.match(statement) is not None:
        return True, "create_index"

    add_column = SQL_ALTER_ADD_COLUMN.match(statement)
    if add_column is not None:
        definition = add_column.group(3)
        if re.search(r"\b(?:DROP|MODIFY|CHANGE|RENAME)\b", definition, re.IGNORECASE):
            return False, "alter_existing_shape"
        if safe_add_column(definition):
            return True, "add_column_safe"
        return False, "add_column_not_safe"

    add_constraint = SQL_ALTER_ADD_CONSTRAINT.match(statement)
    if add_constraint is not None:
        table = sql_identifier(add_constraint.group(1))
        if table in created_tables:
            return True, "add_constraint_new_table"
        return False, "constraint_on_existing_table"
    return False, "statement_not_allowlisted"


def classify_migration_source(source: object) -> tuple[bool, str]:
    """Clasifica únicamente el camino up(); down() no autoriza el ascenso."""
    if not isinstance(source, str) or not source.strip():
        return False, "migration_source_missing"
    section = migration_up_section(source)
    if section is None:
        return False, "up_method_unparsed"
    blocks, parse_error = php_static_add_sql_blocks(section)
    if parse_error is not None:
        return False, parse_error

    created_tables: set[str] = set()
    statement_count = 0
    for block in blocks:
        statements = split_sql_statements(block)
        if not statements:
            return False, "sql_unparsed"
        for statement in statements:
            statement_count += 1
            safe, reason = classify_sql_statement(statement, created_tables)
            if not safe:
                return False, reason
    return (True, "additive_only") if statement_count else (False, "no_sql")


def migration_source_safety(migration_paths: list[str], migration_sources: object) -> dict[str, Any]:
    """Exige fuente exacta para cada migración y clasifica todas fail-closed."""
    if not migration_paths:
        return {"safe": True, "class": "none", "reason": "no_migrations", "files": []}
    if not isinstance(migration_sources, list):
        return {"safe": False, "class": "human", "reason": "migration_sources_missing", "files": []}

    expected = set(migration_paths)
    seen: set[str] = set()
    files: list[dict[str, Any]] = []
    all_safe = True
    for item in migration_sources:
        if not isinstance(item, dict):
            all_safe = False
            files.append({"safe": False, "reason": "invalid_source_entry"})
            continue
        path = item.get("path")
        sha = item.get("sha")
        if not isinstance(path, str) or path not in expected:
            all_safe = False
            files.append({"safe": False, "reason": "unexpected_migration_path"})
            continue
        seen.add(path)
        safe, reason = classify_migration_source(item.get("source"))
        all_safe = all_safe and safe
        files.append({"path": path, "sha": sha, "safe": safe, "reason": reason})

    missing = sorted(expected.difference(seen))
    if missing:
        all_safe = False
        files.extend({"path": path, "safe": False, "reason": "migration_source_missing"} for path in missing)
    return {
        "safe": all_safe,
        "class": "additive" if all_safe else "human",
        "reason": "additive_only" if all_safe else "destructive_or_ambiguous",
        "files": files,
    }


def development_transition_safety(
    changed_paths: Iterable[str],
    migration_sources: object = None,
) -> dict[str, Any]:
    """Clasifica procedencia operativa sin inferir ejecución de comandos."""
    paths = normalized_paths(changed_paths)
    migration_paths = [path for path in paths if path.startswith("migrations/")]
    migration = migration_source_safety(migration_paths, migration_sources)
    command_paths = [
        path
        for path in paths
        if (
            path == "bin/console"
            or path.startswith("src/Console/")
            or (path.startswith("scripts/") and script_requires_release_transition(path))
        )
    ]
    unsafe_commands = sorted(path for path in command_paths if path not in DEVELOPMENT_SAFE_COMMAND_SCRIPTS)
    return {
        "migraciones": migration["safe"],
        "comandos": not unsafe_commands,
        "migration_class": migration["class"],
        "migration_reason": migration["reason"],
        "migration_files": migration["files"],
        "migration_paths": migration_paths,
        "command_paths": command_paths,
        "unsafe_command_paths": unsafe_commands,
    }


def development_auto_validation(
    manifest: dict[str, Any],
    observation: dict[str, Any],
    *,
    phase: str,
    transition_safety: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """Promueve D-043 solo con evidencia exacta y acotada durante construcción."""
    validate_manifest(manifest)
    if not isinstance(observation, dict):
        raise EvidenceError("La observación de desarrollo debe ser un objeto.")

    checks = observation.get("comprobaciones")
    checks = checks if isinstance(checks, dict) else {}
    same_identity = (
        observation.get("version_esperada") == manifest["version"]
        and observation.get("sha_esperado") == manifest["sha"]
    )
    public = public_evidence(observation, manifest["public_checks"])
    schema = checks.get("schema")
    post_deploy = checks.get("post_deploy_status")
    required = {
        item["id"]
        for item in manifest["transition"]["checks"]
        if item.get("required") is True
    }
    unsupported = sorted(required.difference(DEVELOPMENT_AUTO_TRANSITIONS))
    safety = transition_safety if isinstance(transition_safety, dict) else {}

    reasons: list[str] = []
    if phase != DEVELOPMENT_PHASE:
        reasons.append("phase_disabled")
    if not same_identity:
        reasons.append("identity_mismatch")
    if observation.get("estado") not in {
        "DEPLOY_OBSERVED", "VALIDATED_IN_PRODUCTION"
    }:
        reasons.append("deploy_not_observed")
    if not all(item["ok"] for item in public.values()):
        reasons.append("public_smoke_incomplete")
    if not isinstance(schema, dict) or schema.get("ok") is not True:
        reasons.append("schema_not_verified")
    if unsupported:
        reasons.append("unsupported_transition:" + ",".join(unsupported))
    if "migraciones" in required and safety.get("migraciones") is not True:
        reasons.append("migration_not_proven_safe")
    if "comandos" in required and safety.get("comandos") is not True:
        reasons.append("commands_not_proven_safe")
    if (
        not isinstance(post_deploy, dict)
        or post_deploy.get("ok") is not True
        or post_deploy.get("phase") != "complete"
        or post_deploy.get("result") != "success"
    ):
        reasons.append("post_deploy_not_complete")

    observed = dict(observation)
    if reasons:
        observed["estado"] = (
            "DEPLOY_OBSERVED" if same_identity else "NO_OBSERVADO"
        )
        evidence = finalize(manifest, observed, [])
        evidence["development_auto_validation"] = {
            "eligible": False,
            "mode": "validación automática de desarrollo",
            "phase": phase,
            "reason": reasons[0],
            "required": sorted(required),
            "auto_verified": [],
            "migration_class": str(safety.get("migration_class", "unknown")),
        }
        return evidence

    observed["estado"] = "VALIDATED_IN_PRODUCTION"
    evidence = finalize(manifest, observed, sorted(required))
    evidence["development_auto_validation"] = {
        "eligible": True,
        "mode": "validación automática de desarrollo",
        "phase": phase,
        "reason": "exact_evidence_complete",
        "required": sorted(required),
        "auto_verified": sorted(required),
        "migration_class": str(safety.get("migration_class", "unknown")),
    }
    return evidence


def development_validation_comment(evidence: dict[str, Any]) -> str:
    """Comentario idempotente y distinguible para roadmap/revisión pre-live."""
    dev = evidence.get("development_auto_validation")
    if (
        evidence.get("estado") != "VALIDATED_IN_PRODUCTION"
        or not isinstance(dev, dict)
        or dev.get("eligible") is not True
    ):
        raise EvidenceError(
            "Solo una auto-validación de desarrollo elegible puede publicarse."
        )
    payload = {
        "mode": "construccion",
        "sha": evidence["sha"],
        "version": evidence["version"],
        "version_marker": 1,
    }
    marker = "<!-- " + DEVELOPMENT_AUTO_MARKER + " " + json.dumps(
        payload, sort_keys=True, separators=(",", ":")
    ) + " -->"
    verified = ", ".join(
        f'`{item}`' for item in dev.get("auto_verified", [])
    ) or "ninguna transición adicional"
    return (
        marker + "\n"
        "✅ VALIDATED_IN_PRODUCTION · **validación automática de desarrollo**\n\n"
        f"- Versión: `V{evidence['version']}`\n"
        f"- SHA exacto: `{evidence['sha']}`\n"
        "- Fase: `construccion` (fuera de esta fase el mecanismo falla cerrado).\n"
        f"- Transiciones verificadas por evidencia: {verified}.\n"
        f"- Clase de migración: `{dev.get('migration_class', 'unknown')}`.\n"
        "- Evidencia requerida: identidad exacta, smoke público, schema al día y "
        "`post-deploy phase=complete/result=success`.\n"
        "- Migraciones destructivas/ambiguas y transiciones no soportadas no se "
        "auto-validan.\n"
        "- Esta evidencia se acumula para la revisión consolidada pre-live en #389.\n"
    )


def markdown(evidence: dict[str, Any]) -> str:
    """Renderiza la misma evidencia estructurada como resumen humano."""
    lines = [
        "## Evidencia de release Condor",
        "",
        f"- Estado: **{evidence['estado']}**",
        f"- Versión: `{evidence['version']}`",
        f"- SHA: `{evidence['sha']}`",
        "",
        "| Smoke read-only | Resultado | Clase | Detalle |",
        "| --- | --- | --- | --- |",
    ]
    for check_id, item in evidence["public_checks"].items():
        icon = "✅" if item["ok"] else "❌"
        detail = str(item["detalle"]).replace("|", "\\|").replace("\n", " ")
        lines.append(
            f"| `{check_id}` | {icon} | `{item['clase']}` | {detail} |"
        )

    lines.extend(
        [
            "",
            "| Transición | Requerida | Verificada | Resultado |",
            "| --- | --- | --- | --- |",
        ]
    )
    for check_id, item in evidence["transition"]["checks"].items():
        icon = "✅" if item["ok"] else "❌"
        lines.append(
            f"| `{check_id}` | "
            f"{'sí' if item['required'] else 'no'} | "
            f"{'sí' if item['verified'] else 'no'} | {icon} |"
        )

    if evidence["estado"] != "VALIDATED_IN_PRODUCTION":
        lines.extend(
            [
                "",
                "Esta evidencia **no** declara producción validada. "
                "Deploy, transición operativa y validación permanecen separados.",
            ]
        )

    payload = json.dumps(evidence, ensure_ascii=False, sort_keys=True)
    lines.extend(
        [
            "",
            "<details>",
            "<summary>Evidencia JSON</summary>",
            "",
            "```json",
            payload,
            "```",
            "</details>",
        ]
    )
    return "\n".join(lines) + "\n"


def load_envelope() -> tuple[dict[str, Any], dict[str, Any]]:
    """Lee manifiesto y observación por stdin; el CLI no acepta rutas arbitrarias."""
    try:
        payload = json.load(sys.stdin)
    except ValueError as error:
        raise EvidenceError("La entrada de evidencia no es JSON válido.") from error
    if not isinstance(payload, dict):
        raise EvidenceError("La entrada de evidencia debe ser un objeto JSON.")

    manifest = payload.get("manifest")
    observation = payload.get("observation")
    if not isinstance(manifest, dict) or not isinstance(observation, dict):
        raise EvidenceError("La entrada debe contener manifest y observation.")
    return manifest, observation


def load_accumulation_envelope() -> tuple[dict[str, Any], list[dict[str, Any]]]:
    """Lee el manifiesto actual y la lista explícita de manifiestos pendientes."""
    try:
        payload = json.load(sys.stdin)
    except ValueError as error:
        raise EvidenceError("La entrada de acumulación no es JSON válido.") from error
    if not isinstance(payload, dict):
        raise EvidenceError("La entrada de acumulación debe ser un objeto JSON.")

    current = payload.get("current_manifest")
    pending = payload.get("pending_manifests")
    if not isinstance(current, dict) or not isinstance(pending, list):
        raise EvidenceError(
            "La entrada debe contener current_manifest y pending_manifests[]."
        )
    if not all(isinstance(item, dict) for item in pending):
        raise EvidenceError("Cada pending_manifests[*] debe ser un objeto.")
    return current, pending


def main(argv: list[str] | None = None) -> int:
    """Expone generación y consolidación como CLI determinista."""
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)

    manifest_parser = subparsers.add_parser("manifest")
    manifest_parser.add_argument("--sha", required=True)
    manifest_parser.add_argument("--expected-version")

    explicit_parser = subparsers.add_parser("manifest-explicit")
    explicit_parser.add_argument("--version", required=True)
    explicit_parser.add_argument("--sha", required=True)

    subparsers.add_parser("accumulate")

    safety_parser = subparsers.add_parser("development-safety")
    safety_parser.add_argument("--json", action="store_true")

    development_parser = subparsers.add_parser("development-auto")
    development_parser.add_argument("--phase", required=True)
    development_parser.add_argument("--commands-safe", action="store_true")
    development_parser.add_argument("--migrations-safe", action="store_true")
    development_parser.add_argument("--migration-class", choices=("none", "additive", "human", "unknown"), default="unknown")

    subparsers.add_parser("development-comment")

    finalize_parser = subparsers.add_parser("finalize")
    finalize_parser.add_argument(
        "--verified",
        action="append",
        default=[],
        choices=CHECK_IDS,
    )
    finalize_parser.add_argument("--markdown", action="store_true")

    args = parser.parse_args(argv)
    try:
        if args.command == "manifest":
            manifest = build_manifest(
                version_file=CANONICAL_VERSION_FILE,
                sha=args.sha,
                changed_paths=sys.stdin.read().splitlines(),
                expected_version=args.expected_version,
            )
            print(json.dumps(manifest, ensure_ascii=False, indent=2, sort_keys=True))
            return 0

        if args.command == "manifest-explicit":
            manifest = build_manifest_explicit(
                version=args.version,
                sha=args.sha,
                changed_paths=sys.stdin.read().splitlines(),
            )
            print(json.dumps(manifest, ensure_ascii=False, indent=2, sort_keys=True))
            return 0

        if args.command == "accumulate":
            current, pending = load_accumulation_envelope()
            manifest = accumulate_pending_manifests(current, pending)
            print(json.dumps(manifest, ensure_ascii=False, indent=2, sort_keys=True))
            return 0

        if args.command == "development-safety":
            if args.json:
                payload = json.load(sys.stdin)
                if not isinstance(payload, dict):
                    raise EvidenceError("La entrada development-safety debe ser JSON.")
                changed_paths = payload.get("changed_paths")
                if not isinstance(changed_paths, list) or not all(isinstance(path, str) for path in changed_paths):
                    raise EvidenceError("changed_paths debe ser una lista de strings.")
                result = development_transition_safety(changed_paths, payload.get("migration_sources"))
            else:
                result = development_transition_safety(sys.stdin.read().splitlines())
            print(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True))
            return 0

        if args.command == "development-comment":
            payload = json.load(sys.stdin)
            if not isinstance(payload, dict):
                raise EvidenceError("La evidencia de desarrollo debe ser un objeto JSON.")
            print(development_validation_comment(payload), end="")
            return 0

        if args.command == "development-auto":
            manifest, observation = load_envelope()
            evidence = development_auto_validation(
                manifest,
                observation,
                phase=args.phase,
                transition_safety={
                    "comandos": args.commands_safe,
                    "migraciones": args.migrations_safe,
                    "migration_class": args.migration_class,
                },
            )
            print(json.dumps(evidence, ensure_ascii=False, indent=2, sort_keys=True))
            dev = evidence.get("development_auto_validation", {})
            return 0 if (
                evidence["estado"] == "VALIDATED_IN_PRODUCTION"
                and isinstance(dev, dict)
                and dev.get("eligible") is True
            ) else 1

        manifest, observation = load_envelope()
        evidence = finalize(manifest, observation, args.verified)
        payload = json.dumps(evidence, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
        print(markdown(evidence) if args.markdown else payload, end="")
        return 0 if evidence["estado"] == "VALIDATED_IN_PRODUCTION" else 1
    except EvidenceError as error:
        parser.error(str(error))

    return 2


if __name__ == "__main__":
    raise SystemExit(main())
