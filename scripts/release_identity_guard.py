#!/usr/bin/env python3
from __future__ import annotations
import argparse,re,subprocess
from pathlib import Path

VERSION_RE=re.compile(r"""['"]version['"]\s*=>\s*['"](\d+\.\d+\.\d+)['"]""")
TITLE_RE=re.compile(r"\(V (\d+\.\d+\.\d+)\)$")
TAG_RE=re.compile(r"^v(\d+)\.(\d+)\.(\d+)$")
SHA_RE=re.compile(r"^[0-9a-f]{40}$")

class GuardError(RuntimeError): pass

def sv(s):
    m=re.fullmatch(r"(\d+)\.(\d+)\.(\d+)",s)
    if not m: raise GuardError(f"release_identity_guard: invalid SemVer {s!r}.")
    return tuple(map(int,m.groups()))

def git(root,*args):
    p=subprocess.run(["git",*args],cwd=root,text=True,capture_output=True)
    if p.returncode: raise GuardError("release_identity_guard: "+(p.stderr.strip() or p.stdout.strip()))
    return p.stdout.strip()

def validate(root,title,sha):
    root=Path(root)
    if not SHA_RE.fullmatch(sha.lower()): raise GuardError("release_identity_guard: candidate SHA must be a full 40-char commit SHA.")
    versions=VERSION_RE.findall((root/"config/version.php").read_text(encoding="utf-8"))
    if len(versions)!=1: raise GuardError("release_identity_guard: config/version.php must define exactly one canonical version.")
    version=versions[0]; sv(version)
    m=TITLE_RE.search(title)
    if not m: raise GuardError("release_identity_guard: PR title must end with exact format (V X.Y.Z).")
    if m.group(1)!=version: raise GuardError(f"release_identity_guard: PR title version {m.group(1)} does not match config/version.php {version}.")
    tags=[]
    for tag in git(root,"tag","--list").splitlines():
        tm=TAG_RE.fullmatch(tag.strip())
        if tm: tags.append((tuple(map(int,tm.groups())),tag.strip()))
    tags.sort()
    candidate=f"v{version}"
    names={t for _,t in tags}
    if candidate in names:
        target=git(root,"rev-list","-n","1",candidate)
        relation="candidate SHA" if target==sha.lower() else "different SHA"
        raise GuardError(f"release_identity_guard: candidate tag {candidate} already exists on {relation} {target}; release identities are single-use.")
    if tags and sv(version)<=tags[-1][0]:
        latest=".".join(map(str,tags[-1][0]))
        raise GuardError(f"release_identity_guard: candidate version {version} must be greater than latest SemVer release {latest}.")
    return candidate,tags[-1][1] if tags else "none"

def main():
    p=argparse.ArgumentParser()
    p.add_argument("--repo-root",default=".")
    p.add_argument("--pr-title",required=True)
    p.add_argument("--candidate-sha",required=True)
    a=p.parse_args()
    try:
        candidate,latest=validate(Path(a.repo_root).resolve(),a.pr_title,a.candidate_sha)
    except (GuardError,OSError) as e:
        print(f"::error::{e}"); return 1
    print(f"release_identity_guard: OK candidate={candidate} sha={a.candidate_sha.lower()} latest={latest}")
    return 0
if __name__=="__main__": raise SystemExit(main())
