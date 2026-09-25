#!/usr/bin/env python3
from __future__ import annotations
import os, re, shlex, subprocess, sys, tempfile
from contextlib import contextmanager
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parents[2]
HOST_RE = re.compile(r"^(?=.{1,253}$)(?!-)[A-Za-z0-9.-]+(?<!-)$")
USER_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$")
SHA_RE = re.compile(r"^[0-9a-f]{40}$")

class AdapterError(RuntimeError): pass

def env(name: str) -> str:
    value=os.environ.get(name,"").strip()
    if not value: raise AdapterError(f"falta {name}")
    return value

def release_root() -> str:
    raw=env("HOSTINGER_RELEASE_ROOT")
    if not raw.startswith("/") or "\x00" in raw or "\\" in raw: raise AdapterError("HOSTINGER_RELEASE_ROOT inválido")
    p=PurePosixPath(raw)
    if str(p)!=raw.rstrip("/") or str(p)=="/" or any(x in {"",".",".."} for x in p.parts[1:]):
        raise AdapterError("HOSTINGER_RELEASE_ROOT inválido")
    return str(p)

def sha() -> str:
    value=env("GITHUB_SHA")
    if not SHA_RE.fullmatch(value): raise AdapterError("GITHUB_SHA inválido")
    return value

def run(cmd:list[str], *, cwd:Path=ROOT, extra_env:dict[str,str]|None=None) -> None:
    merged=os.environ.copy()
    if extra_env: merged.update(extra_env)
    subprocess.run(cmd,cwd=cwd,env=merged,check=True)

@contextmanager
def ssh_material():
    host=env("HOSTINGER_SSH_HOST"); user=env("HOSTINGER_SSH_USER"); port=env("HOSTINGER_SSH_PORT")
    labels=host.split(".")
    if (
        not HOST_RE.fullmatch(host)
        or any(not label or len(label)>63 or label.startswith("-") or label.endswith("-") for label in labels)
        or not USER_RE.fullmatch(user)
        or not port.isdigit()
        or not 1<=int(port)<=65535
    ):
        raise AdapterError("configuración SSH inválida")
    known=env("HOSTINGER_KNOWN_HOSTS"); key=env("DEPLOY_SSH_KEY")
    with tempfile.TemporaryDirectory(prefix="condor-factory-") as td:
        td=Path(td); keyfile=td/"key"; knownfile=td/"known_hosts"
        keyfile.write_text(key,encoding="utf-8"); keyfile.chmod(0o600)
        knownfile.write_text(known+"\n",encoding="utf-8"); knownfile.chmod(0o600)
        base=["ssh","-i",str(keyfile),"-p",port,"-o","BatchMode=yes","-o","IdentitiesOnly=yes",
              "-o","StrictHostKeyChecking=yes","-o",f"UserKnownHostsFile={knownfile}",f"{user}@{host}"]
        yield base, host, user, port, keyfile, knownfile

def remote(base:list[str], script:str, *args:str) -> None:
    command=" ".join([script,*[shlex.quote(a) for a in args]])
    run([*base,command])

def build() -> None:
    run(["composer","install","--no-dev","--prefer-dist","--no-interaction","--optimize-autoloader"])
    run(["npm","ci"])
    run(["npm","run","build:admin"])

def backup() -> None:
    run(["sh","scripts/backup-database.sh"], extra_env={"BACKUP_DIR":str(ROOT/"var/backups")})

def migrate() -> None:
    phase=os.environ.get("PHASE","construccion")
    mapped={"construccion":"construction","live":"live"}.get(phase)
    if mapped is None: raise AdapterError("PHASE inválida")
    run(["sh","scripts/post-deploy.sh"], extra_env={"CONDOR_PRODUCTION_STAGE":mapped})

def deploy() -> None:
    root=release_root(); commit=sha()
    release=f"{root}/releases/{commit}"
    with ssh_material() as (ssh,host,user,port,keyfile,knownfile):
        preflight=(
            'set -eu; root=$1; rel=$2; umask 077; '
            'mkdir -p "$root" "$root/releases" "$root/shared/var/runtime" "$rel"; '
            'for p in "$root" "$root/releases" "$root/shared" "$root/shared/var" "$root/shared/var/runtime" "$rel"; '
            'do [ ! -L "$p" ] || exit 30; done'
        )
        remote(ssh,"sh -c",preflight,"condor-deploy-preflight",root,release)
        rsync_ssh=f"ssh -i {shlex.quote(str(keyfile))} -p {port} -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile={shlex.quote(str(knownfile))}"
        run(["rsync","-a","--delete","--chmod=Du=rwx,Dgo=rx,Fu=rw,Fgo=r",
             "--exclude=.git/","--exclude=.github/","--exclude=.env","--exclude=.env.*",
             "--exclude=.release-sha","--exclude=node_modules/","--exclude=tests/","--exclude=var/",
             "-e",rsync_ssh,f"{ROOT}/",f"{user}@{host}:{release}/"])
        finalize=(
            'set -eu; root=$1; rel=$2; sha=$3; shared=$root/shared; '
            '[ -d "$rel" ] && [ ! -L "$rel" ] || exit 31; '
            'mkdir -p "$rel/var/cache" "$rel/var/log" "$rel/var/runtime"; '
            'chmod 700 "$rel/var" "$rel/var/cache" "$rel/var/log" "$rel/var/runtime" 2>/dev/null || true; '
            'if [ -L "$shared/var/runtime/app_secret" ]; then exit 32; '
            'elif [ -f "$shared/var/runtime/app_secret" ]; then '
            'cp "$shared/var/runtime/app_secret" "$rel/var/runtime/app_secret"; chmod 600 "$rel/var/runtime/app_secret"; fi; '
            'if [ -L "$shared/.env" ]; then exit 33; '
            'elif [ -f "$shared/.env" ]; then cp "$shared/.env" "$rel/.env"; chmod 600 "$rel/.env"; fi; '
            'printf "%s\\n" "$sha" > "$rel/.release-sha.tmp"; chmod 600 "$rel/.release-sha.tmp"; '
            'mv -f "$rel/.release-sha.tmp" "$rel/.release-sha"; '
            'prev=$root/.previous; cur=$root/current; '
            'if [ -L "$cur" ]; then old=$(readlink "$cur"); '
            'case "$old" in "$root"/releases/*) ;; *) exit 34 ;; esac; '
            'if [ "$old" != "$rel" ]; then printf "%s\\n" "$old" > "$prev.tmp"; mv -f "$prev.tmp" "$prev"; fi; '
            'elif [ -e "$cur" ]; then exit 35; fi; '
            'rm -f "$root/current.next"; ln -s "$rel" "$root/current.next"; mv -Tf "$root/current.next" "$cur"'
        )
        remote(ssh,"sh -c",finalize,"condor-deploy",root,release,commit)

def rollback() -> None:
    root=release_root()
    with ssh_material() as (ssh,*_):
        script=(
            'set -eu; root=$1; prev=$root/.previous; [ -f "$prev" ] || exit 20; '
            'target=$(cat "$prev"); case "$target" in "$root"/releases/*) ;; *) exit 21 ;; esac; '
            '[ -d "$target" ] && [ ! -L "$target" ] || exit 22; '
            '[ -f "$target/.release-sha" ] && [ ! -L "$target/.release-sha" ] || exit 23; '
            'release_sha=$(cat "$target/.release-sha"); [ "$target" = "$root/releases/$release_sha" ] || exit 24; '
            'rm -f "$root/current.next"; ln -s "$target" "$root/current.next"; mv -Tf "$root/current.next" "$root/current"'
        )
        remote(ssh,"sh -c",script,"condor-rollback",root)

STAGES={"build":build,"backup":backup,"migrate":migrate,"deploy":deploy,"rollback":rollback}
def main() -> int:
    if len(sys.argv)!=2 or sys.argv[1] not in STAGES:
        print("uso: adapter.py <build|backup|migrate|deploy|rollback>",file=sys.stderr); return 2
    try: STAGES[sys.argv[1]]()
    except (AdapterError,subprocess.CalledProcessError) as exc:
        print(f"adapter {sys.argv[1]} falló: {exc}",file=sys.stderr); return 1
    return 0
if __name__=="__main__": raise SystemExit(main())
