#!/usr/bin/env python3
"""Governed Condor production-recovery rollout orchestration.

The script never implements provider I/O. It prepares canonical FactoryRunner
Recovery descriptors and finalizes only sanitized receipts returned by that
execution boundary. Database backup/restore are delegated to Condor's existing
governed scripts.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
BACKUP_GLOB = "condor-*.sql.gz"
OPAQUE_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$")
CONNECTION_RE = re.compile(r"^controlbot:connection/[A-Za-z0-9][A-Za-z0-9._:/#-]{0,159}$")
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
SAFE_REF_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$")
SENSITIVE_RE = re.compile(
    r"(?:-----BEGIN [^-]*PRIVATE KEY-----|\bbearer\s+\S+|"
    r"\b(?:password|passwd|token|secret|cookie|authorization|private[_ -]?key|"
    r"api[_ -]?key|dsn)\s*[:=]\s*\S+|://[^/\s]+:[^@\s]+@)",
    re.IGNORECASE,
)


class RecoveryRolloutError(RuntimeError):
    """Fail-closed error with deliberately non-sensitive messages."""


def utc_now() -> datetime:
    return datetime.now(timezone.utc)


def utc_text(value: datetime) -> str:
    return value.astimezone(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def parse_utc(value: str) -> datetime:
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as exc:
        raise RecoveryRolloutError("timestamp UTC inválido") from exc
    if parsed.tzinfo is None:
        raise RecoveryRolloutError("timestamp UTC inválido")
    return parsed.astimezone(timezone.utc)


def safe_ref(value: Any, label: str) -> str:
    if not isinstance(value, str) or not SAFE_REF_RE.fullmatch(value):
        raise RecoveryRolloutError(f"{label} inválida")
    if ".." in value or "://" in value or "@" in value or SENSITIVE_RE.search(value):
        raise RecoveryRolloutError(f"{label} inválida")
    return value


def connection_ref(value: Any, label: str = "connection_ref") -> str:
    if not isinstance(value, str) or not CONNECTION_RE.fullmatch(value):
        raise RecoveryRolloutError(f"{label} inválida")
    if ".." in value or "://" in value or "@" in value or SENSITIVE_RE.search(value):
        raise RecoveryRolloutError(f"{label} inválida")
    return value


def checksum_text(value: Any, label: str = "checksum_sha256") -> str:
    if not isinstance(value, str) or not SHA256_RE.fullmatch(value):
        raise RecoveryRolloutError(f"{label} inválido")
    return value


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def stable_sha256(value: Any) -> str:
    encoded = json.dumps(
        value, ensure_ascii=False, sort_keys=True, separators=(",", ":")
    ).encode("utf-8")
    return hashlib.sha256(encoded).hexdigest()


def descriptor(
    *,
    provider: str,
    role: str,
    operation: str,
    object_ref: str,
    checksum_sha256: str,
    idempotency_key: str,
) -> dict[str, Any]:
    if provider == "object_storage":
        if role != "primary_offsite":
            raise RecoveryRolloutError("rol primary offsite inválido")
    elif provider == "google_drive":
        if role != "cold_copy":
            raise RecoveryRolloutError("rol cold copy inválido")
    else:
        raise RecoveryRolloutError("provider Recovery no soportado")
    if operation not in {"upload", "materialize", "verify"}:
        raise RecoveryRolloutError("operación Recovery no soportada")

    base = {
        "version": 1,
        "project": "condor",
        "provider": provider,
        "role": role,
        "operation": operation,
        "namespace": "condor.production.backups",
        "object_ref": safe_ref(object_ref, "object_ref"),
        "checksum_sha256": checksum_text(checksum_sha256),
        "idempotency_key": safe_ref(idempotency_key, "idempotency_key"),
        "authority": "unchanged",
        "execute": False,
    }
    return {**base, "descriptor_id": stable_sha256(base)}


def newest_backup(before: set[Path]) -> Path:
    backup_dir = ROOT / "var" / "backups"
    after = {p for p in backup_dir.glob(BACKUP_GLOB) if p.is_file()}
    created = after - before
    if len(created) != 1:
        raise RecoveryRolloutError("backup real no produjo un artefacto único verificable")
    return created.pop()


def run_backup() -> Path:
    backup_dir = ROOT / "var" / "backups"
    before = {p for p in backup_dir.glob(BACKUP_GLOB) if p.is_file()} if backup_dir.exists() else set()
    try:
        subprocess.run(
            [sys.executable, "ops/factory/adapter.py", "backup"],
            cwd=ROOT,
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
    except subprocess.CalledProcessError as exc:
        raise RecoveryRolloutError("backup de producción falló") from exc
    return newest_backup(before)


def write_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def load_json(path: Path, label: str) -> dict[str, Any]:
    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise RecoveryRolloutError(f"{label} inválido") from exc
    if not isinstance(raw, dict):
        raise RecoveryRolloutError(f"{label} inválido")
    return raw


def prepare(
    *,
    offsite_connection_ref: str,
    output: Path,
    cold_copy_connection_ref: str | None = None,
    backup_file: Path | None = None,
) -> dict[str, Any]:
    # Validate aliases before touching the production database.
    primary_connection = connection_ref(offsite_connection_ref, "offsite connection_ref")
    cold_connection = (
        connection_ref(cold_copy_connection_ref, "cold-copy connection_ref")
        if cold_copy_connection_ref
        else None
    )

    backup = backup_file.resolve() if backup_file else run_backup()
    if not backup.is_file():
        raise RecoveryRolloutError("backup verificable ausente")
    digest = sha256_file(backup)
    created_at = utc_now()
    stamp = created_at.strftime("%Y%m%dT%H%M%SZ")
    object_ref = f"condor.backup.{stamp}.{digest[:12]}"
    idem = f"condor.recovery.{stamp}.{digest[:12]}"

    primary_descriptor = descriptor(
        provider="object_storage",
        role="primary_offsite",
        operation="upload",
        object_ref=object_ref,
        checksum_sha256=digest,
        idempotency_key=idem,
    )
    manifest: dict[str, Any] = {
        "version": 1,
        "project": "condor",
        "state": "PREPARED",
        "backup_path": str(backup),
        "backup_created_at": utc_text(created_at),
        "checksum_sha256": digest,
        "primary_request": {
            "capability": "recovery.object-storage.upload",
            "connection_ref": primary_connection,
            "descriptor": primary_descriptor,
        },
    }
    if cold_connection:
        manifest["cold_copy_request"] = {
            "capability": "recovery.google-drive.upload",
            "connection_ref": cold_connection,
            "descriptor": descriptor(
                provider="google_drive",
                role="cold_copy",
                operation="upload",
                object_ref=object_ref,
                checksum_sha256=digest,
                idempotency_key=idem,
            ),
        }

    write_json(output, manifest)
    return manifest


PRIMARY_RECEIPT_KEYS = {
    "capability",
    "operation",
    "descriptor_id",
    "object_ref",
    "checksum_sha256",
    "immutable_version_ref",
    "evidence_ref",
}
COLD_RECEIPT_KEYS = {
    "capability",
    "operation",
    "descriptor_id",
    "object_ref",
    "checksum_sha256",
    "remote_version_ref",
    "evidence_ref",
}


def validate_primary_receipt(manifest: dict[str, Any], receipt: dict[str, Any]) -> dict[str, str]:
    if set(receipt) != PRIMARY_RECEIPT_KEYS:
        raise RecoveryRolloutError("recibo primary offsite inválido")
    descriptor_value = manifest["primary_request"]["descriptor"]
    expected = {
        "capability": "recovery.object-storage.upload",
        "operation": "upload",
        "descriptor_id": descriptor_value["descriptor_id"],
        "object_ref": descriptor_value["object_ref"],
        "checksum_sha256": descriptor_value["checksum_sha256"],
    }
    for key, value in expected.items():
        if receipt.get(key) != value:
            raise RecoveryRolloutError("recibo primary offsite no coincide")
    return {
        "immutable_version_ref": safe_ref(receipt["immutable_version_ref"], "immutable_version_ref"),
        "evidence_ref": safe_ref(receipt["evidence_ref"], "evidence_ref"),
    }


def validate_cold_receipt(manifest: dict[str, Any], receipt: dict[str, Any]) -> dict[str, str]:
    request = manifest.get("cold_copy_request")
    if not request:
        raise RecoveryRolloutError("cold copy no fue solicitada")
    if set(receipt) != COLD_RECEIPT_KEYS:
        raise RecoveryRolloutError("recibo cold copy inválido")
    descriptor_value = request["descriptor"]
    expected = {
        "capability": "recovery.google-drive.upload",
        "operation": "upload",
        "descriptor_id": descriptor_value["descriptor_id"],
        "object_ref": descriptor_value["object_ref"],
        "checksum_sha256": descriptor_value["checksum_sha256"],
    }
    for key, value in expected.items():
        if receipt.get(key) != value:
            raise RecoveryRolloutError("recibo cold copy no coincide")
    return {
        "remote_version_ref": safe_ref(receipt["remote_version_ref"], "remote_version_ref"),
        "evidence_ref": safe_ref(receipt["evidence_ref"], "evidence_ref"),
    }


def run_restore(backup: Path) -> float:
    restore_url = os.environ.get("CONDOR_DISPOSABLE_RESTORE_DATABASE_URL", "").strip()
    source_url = os.environ.get("DATABASE_URL", "").strip()
    if not restore_url:
        raise RecoveryRolloutError("target descartable no provisionado")
    if source_url and source_url == restore_url:
        raise RecoveryRolloutError("target descartable coincide con la fuente")

    env = os.environ.copy()
    env["DATABASE_URL"] = restore_url
    env["CONDOR_ALLOW_DESTRUCTIVE_RESTORE"] = "1"
    started = time.monotonic()
    try:
        subprocess.run(
            ["sh", "scripts/verify-backup-restore.sh", str(backup)],
            cwd=ROOT,
            env=env,
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
    except subprocess.CalledProcessError as exc:
        raise RecoveryRolloutError("restore drill descartable falló") from exc
    return time.monotonic() - started


def finalize(
    *,
    manifest_path: Path,
    primary_receipt_path: Path,
    output: Path,
    cold_receipt_path: Path | None = None,
    max_rpo_seconds: int = 86400,
) -> dict[str, Any]:
    manifest = load_json(manifest_path, "manifest Recovery")
    if manifest.get("state") != "PREPARED" or manifest.get("project") != "condor":
        raise RecoveryRolloutError("manifest Recovery fuera de contrato")

    backup = Path(str(manifest.get("backup_path", ""))).resolve()
    if not backup.is_file():
        raise RecoveryRolloutError("backup local ausente para restore drill")
    expected_checksum = checksum_text(manifest.get("checksum_sha256"))
    if sha256_file(backup) != expected_checksum:
        raise RecoveryRolloutError("checksum del backup cambió")

    primary = validate_primary_receipt(
        manifest, load_json(primary_receipt_path, "recibo primary offsite")
    )

    cold: dict[str, str] | None = None
    cold_requested = "cold_copy_request" in manifest
    if cold_receipt_path:
        cold = validate_cold_receipt(
            manifest, load_json(cold_receipt_path, "recibo cold copy")
        )

    rto_seconds = run_restore(backup)
    finished_at = utc_now()
    backup_created_at = parse_utc(str(manifest["backup_created_at"]))
    rpo_seconds = max(0.0, (finished_at - backup_created_at).total_seconds())
    freshness = "fresh" if rpo_seconds <= max_rpo_seconds else "stale"
    if freshness != "fresh":
        raise RecoveryRolloutError("evidencia Recovery stale")

    report: dict[str, Any] = {
        "version": 1,
        "project": "condor",
        "status": "HEALTHY",
        "checked_at": utc_text(finished_at),
        "checksum_sha256": expected_checksum,
        "primary_offsite": {
            "immutable_version_ref": primary["immutable_version_ref"],
            "evidence_ref": primary["evidence_ref"],
        },
        "restore": {
            "target": "disposable",
            "status": "verified",
            "rto_seconds": round(rto_seconds, 3),
        },
        "freshness": {
            "status": freshness,
            "rpo_seconds": round(rpo_seconds, 3),
            "max_rpo_seconds": max_rpo_seconds,
        },
        "cold_copy": {
            "provider": "google_drive",
            "status": "verified" if cold else ("not_requested" if not cold_requested else "pending"),
        },
    }
    if cold:
        report["cold_copy"].update(cold)

    write_json(output, report)
    return report


def parser() -> argparse.ArgumentParser:
    root = argparse.ArgumentParser(description="Condor governed Recovery rollout")
    commands = root.add_subparsers(dest="command", required=True)

    preflight_cmd = commands.add_parser("preflight")
    preflight_cmd.add_argument("--offsite-connection-ref", required=True)
    preflight_cmd.add_argument("--cold-copy-connection-ref")

    prepare_cmd = commands.add_parser("prepare")
    prepare_cmd.add_argument("--offsite-connection-ref", required=True)
    prepare_cmd.add_argument("--cold-copy-connection-ref")
    prepare_cmd.add_argument("--output", type=Path, required=True)
    prepare_cmd.add_argument("--backup-file", type=Path)

    finalize_cmd = commands.add_parser("finalize")
    finalize_cmd.add_argument("--manifest", type=Path, required=True)
    finalize_cmd.add_argument("--primary-receipt", type=Path, required=True)
    finalize_cmd.add_argument("--cold-receipt", type=Path)
    finalize_cmd.add_argument("--output", type=Path, required=True)
    finalize_cmd.add_argument("--max-rpo-seconds", type=int, default=86400)
    return root


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    try:
        if args.command == "preflight":
            connection_ref(args.offsite_connection_ref, "offsite connection_ref")
            if args.cold_copy_connection_ref:
                connection_ref(args.cold_copy_connection_ref, "cold-copy connection_ref")
            summary = {"state": "READY_FOR_RECOVERY_PREFLIGHT"}
        elif args.command == "prepare":
            result = prepare(
                offsite_connection_ref=args.offsite_connection_ref,
                cold_copy_connection_ref=args.cold_copy_connection_ref,
                output=args.output,
                backup_file=args.backup_file,
            )
            summary = {
                "state": result["state"],
                "descriptor_id": result["primary_request"]["descriptor"]["descriptor_id"],
                "checksum_sha256": result["checksum_sha256"],
            }
        else:
            if args.max_rpo_seconds < 1:
                raise RecoveryRolloutError("max_rpo_seconds inválido")
            result = finalize(
                manifest_path=args.manifest,
                primary_receipt_path=args.primary_receipt,
                cold_receipt_path=args.cold_receipt,
                output=args.output,
                max_rpo_seconds=args.max_rpo_seconds,
            )
            summary = {
                "status": result["status"],
                "checked_at": result["checked_at"],
                "freshness": result["freshness"]["status"],
            }
    except RecoveryRolloutError as exc:
        print(f"recovery-production: {exc}", file=sys.stderr)
        return 1

    print(json.dumps(summary, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
