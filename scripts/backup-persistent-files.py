#!/usr/bin/env python3
"""Copia y restaura archivos persistentes en destinos explícitos y aislados.

No maneja almacenamiento remoto, datos reales ni eliminación por retención.
El operador define el origen; la restauración solo crea un destino inexistente.
"""

import argparse
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import secrets
import shutil
import stat
import tarfile
import tempfile
from datetime import datetime, timezone


MAX_FILES = 10000
MAX_FILE_BYTES = 10 * 1024 ** 3
ARCHIVE_FILES_PREFIX = "files/"
SYNTHETIC_MARKER = ".condor-recovery-synthetic-only"


def sandbox_root():
    """Only operate on the caller's explicit, marked disposable test directory."""
    raw = os.environ.get("CONDOR_RECOVERY_SANDBOX_ROOT", "")
    if not raw:
        fail("falta el directorio aislado de simulación")
    path = Path(raw)
    if path.is_symlink() or not path.is_dir():
        fail("sandbox inexistente o simbólico")
    root = path.resolve(strict=True)
    if root == Path(root.anchor):
        fail("la raíz del sistema no es un sandbox")
    marker = root / SYNTHETIC_MARKER
    if marker.is_symlink() or not marker.is_file():
        fail("falta la marca de entorno sintético")
    if marker.read_text(encoding="utf-8") != "synthetic-only\n":
        fail("el entorno no es descartable")
    return root



def fail(message):
    raise ValueError(message)


def safe_name(name):
    if not isinstance(name, str) or not name or "\\" in name or "\x00" in name:
        fail("nombre de archivo inválido")
    parsed = PurePosixPath(name)
    if parsed.is_absolute() or any(part in ("", ".", "..") for part in name.split("/")):
        fail("ruta de archivo no relativa y canónica")
    return parsed


def sha256_file(path):
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def inside(path, root):
    return path == root or root in path.parents


def collect(source):
    files = []
    for path in sorted(source.rglob("*")):
        if path.is_symlink():
            fail("los enlaces simbólicos no son respaldables")
        mode = path.lstat().st_mode
        if stat.S_ISDIR(mode):
            continue
        if not stat.S_ISREG(mode):
            fail("solo se permiten archivos regulares")
        rel = path.relative_to(source).as_posix()
        safe_name(rel)
        size = path.stat().st_size
        if size > MAX_FILE_BYTES:
            fail("archivo mayor que el límite del formato")
        files.append({"path": rel, "size": size, "sha256": sha256_file(path)})
        if len(files) > MAX_FILES:
            fail("demasiados archivos")
    if not files:
        fail("el origen no contiene archivos persistentes")
    return files


def load_manifest(bundle, members):
    if not members or members[-1].name != "MANIFEST.json":
        fail("manifiesto ausente o fuera de posición")
    if len(members) > MAX_FILES + 1 or any(not m.isfile() for m in members):
        fail("tar contiene objetos no permitidos")
    last = members[-1]
    if last.size > 5 * 1024 * 1024:
        fail("manifiesto excesivo")
    raw = bundle.extractfile(last)
    if raw is None:
        fail("manifiesto inaccesible")
    manifest = json.loads(raw.read())
    if (not isinstance(manifest, dict) or manifest.get("version") != 1
            or not isinstance(manifest.get("created_at"), str)
            or not isinstance(manifest.get("files"), list)):
        fail("contrato de manifiesto incorrecto")
    if not manifest["files"] or len(manifest["files"]) != len(members) - 1:
        fail("cantidad de archivos no coincide")
    return manifest


def verify_inventory(members, entries):
    expected = []
    for entry in entries:
        if not isinstance(entry, dict) or set(entry) != {"path", "size", "sha256"}:
            fail("entrada de manifiesto inválida")
        rel = safe_name(entry["path"])
        if (not isinstance(entry["size"], int) or isinstance(entry["size"], bool)
                or entry["size"] < 0 or entry["size"] > MAX_FILE_BYTES
                or not isinstance(entry["sha256"], str)
                or len(entry["sha256"]) != 64
                or any(ch not in "0123456789abcdef" for ch in entry["sha256"])):
            fail("hash o tamaño inválido")
        expected.append(ARCHIVE_FILES_PREFIX + rel.as_posix())
    if expected != sorted(set(expected)):
        fail("archivos repetidos o desordenados")
    if [member.name for member in members[:-1]] != expected:
        fail("archivo ausente o extra")
    for member, entry in zip(members[:-1], entries):
        if member.size != entry["size"]:
            fail("tamaño del contenido no coincide")


def inspect_archive(archive):
    with tarfile.open(archive, "r:gz") as bundle:
        members = bundle.getmembers()
        manifest = load_manifest(bundle, members)
        verify_inventory(members, manifest["files"])
        for member, entry in zip(members[:-1], manifest["files"]):
            stream = bundle.extractfile(member)
            if stream is None:
                fail("contenido inaccesible")
            digest = hashlib.sha256()
            for chunk in iter(lambda: stream.read(1024 * 1024), b""):
                digest.update(chunk)
            if digest.hexdigest() != entry["sha256"]:
                fail("integridad SHA-256 incorrecta")
    return manifest


def backup():
    root = sandbox_root()
    source = root / "condor-files-source"
    backup_dir = root / "condor-files-private"
    if source.is_symlink() or not source.is_dir():
        fail("origen inexistente o simbólico")
    src = source.resolve(strict=True)
    proposed = backup_dir.resolve(strict=False)
    if inside(proposed, src) or inside(src, proposed):
        fail("origen y destino se solapan")
    backup_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    if backup_dir.is_symlink():
        fail("destino de backups simbólico")
    out = backup_dir.resolve(strict=True)
    if inside(out, src) or inside(src, out):
        fail("origen y destino se solapan")
    entries = collect(src)
    now = datetime.now(timezone.utc)
    manifest = {"version": 1, "created_at": now.isoformat(timespec="seconds"), "files": entries}
    name = f"condor-files-{now.strftime('%Y%m%dT%H%M%SZ')}-{secrets.token_hex(5)}.tar.gz"
    fd, path = tempfile.mkstemp(prefix=".condor-files-", suffix=".tmp", dir=out)
    os.chmod(path, 0o600)
    try:
        with os.fdopen(fd, "wb") as handle, tarfile.open(fileobj=handle, mode="w:gz") as bundle:
            for entry in entries:
                source_path = src / entry["path"]
                if source_path.is_symlink() or not source_path.is_file():
                    fail("el origen cambió durante el backup")
                info = tarfile.TarInfo(ARCHIVE_FILES_PREFIX + entry["path"])
                info.size = entry["size"]
                info.mode = 0o600
                with source_path.open("rb") as file_handle:
                    bundle.addfile(info, file_handle)
            raw = json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode()
            info = tarfile.TarInfo("MANIFEST.json")
            info.mode = 0o600
            info.size = len(raw)
            bundle.addfile(info, io.BytesIO(raw))
        inspect_archive(Path(path))  # fail closed si los archivos cambiaron durante la copia
        final = out / name
        os.replace(path, final)
        return {"status": "created", "created_at": manifest["created_at"],
                "file_count": len(entries), "sha256": sha256_file(final), "archive_name": name}
    finally:
        if os.path.exists(path):
            os.unlink(path)


def restore():
    root = sandbox_root()
    store = root / "condor-files-private"
    if store.is_symlink() or not store.is_dir():
        fail("almacén sintético inexistente o simbólico")
    matches = sorted(store.glob("condor-files-*.tar.gz"))
    if len(matches) != 1:
        fail("se requiere exactamente un archivo de backup sintético")
    archive = matches[0]
    destination = root / "condor-files-restored"
    if not archive.is_file() or archive.is_symlink():
        fail("backup no disponible o simbólico")
    if destination.exists() or destination.is_symlink():
        fail("restauración exige destino inexistente")
    parent = destination.parent.resolve(strict=True)
    if not parent.is_dir():
        fail("padre del destino no es directorio")
    if inside(destination.resolve(), archive.resolve().parent):
        fail("no restaurar dentro del almacén de backups")
    manifest = inspect_archive(archive)
    stage = Path(tempfile.mkdtemp(prefix=".condor-restore-", dir=parent))
    try:
        with tarfile.open(archive, "r:gz") as bundle:
            for entry in manifest["files"]:
                target = stage.joinpath(*safe_name(entry["path"]).parts)
                target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                stream = bundle.extractfile(ARCHIVE_FILES_PREFIX + entry["path"])
                if stream is None:
                    fail("el archivo desapareció del backup")
                with target.open("xb") as out:
                    shutil.copyfileobj(stream, out)
                target.chmod(0o600)
                if target.stat().st_size != entry["size"] or sha256_file(target) != entry["sha256"]:
                    fail("restauración no íntegra")
        if destination.exists() or destination.is_symlink():
            fail("el destino fue creado por otro proceso")
        os.rename(stage, destination)
    finally:
        if stage.exists():
            shutil.rmtree(stage)
    return {"status": "restored", "created_at": manifest["created_at"],
            "file_count": len(manifest["files"]), "sha256": sha256_file(archive)}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_subparsers(dest="mode", required=True)
    modes.add_parser("backup")
    modes.add_parser("restore")
    args = parser.parse_args()
    try:
        result = backup() if args.mode == "backup" else restore()
    except (ValueError, OSError, EOFError, tarfile.TarError) as error:
        parser.exit(1, f"backup-persistent-files.py: operación rechazada: {type(error).__name__}\n")
    print(json.dumps(result, sort_keys=True))


if __name__ == "__main__":
    main()
