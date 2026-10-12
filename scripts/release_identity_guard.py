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


def _verified_pr_version(root, sha, base_sha, candidate, version, tags, names):
    """Only a PR based on authenticated main may reuse its own existing tag."""
    if not isinstance(base_sha, str) or not SHA_RE.fullmatch(base_sha.lower()):
        raise GuardError("release_identity_guard: PR requires trusted 40-char base SHA.")
    if git(root, "cat-file", "-t", f"{base_sha}^{{commit}}") != "commit":
        raise GuardError("release_identity_guard: untrusted base commit.")
    if git(root, "cat-file", "-t", f"{sha}^{{commit}}") != "commit":
        raise GuardError("release_identity_guard: untrusted PR head commit.")
    if git(root, "rev-parse", "refs/remotes/origin/main") != base_sha:
        raise GuardError("release_identity_guard: PR base is not fetched origin/main.")
    if git(root, "merge-base", base_sha, sha) != base_sha:
        raise GuardError("release_identity_guard: PR head is not based on main.")
    original = VERSION_RE.findall(git(root, "show", f"{base_sha}:config/version.php"))
    if len(original) != 1:
        raise GuardError("release_identity_guard: invalid trusted base version.")
    base_version = original[0]
    if sv(version) < sv(base_version):
        raise GuardError("release_identity_guard: PR version regresses main.")
    if version != base_version:
        return None
    if candidate not in names:
        raise GuardError("release_identity_guard: unchanged PR version lacks authentic tag.")
    if git(root, "cat-file", "-t", f"refs/tags/{candidate}") != "tag":
        raise GuardError("release_identity_guard: base tag is not annotated.")
    if git(root, "rev-list", "-n", "1", candidate) != base_sha:
        raise GuardError("release_identity_guard: release tag differs from trusted base.")
    if not tags or tags[-1][0] != sv(version):
        raise GuardError("release_identity_guard: base release tag is not latest.")
    return candidate, tags[-1][1]


def validate(root,title,sha, *, context='release', base_sha=None):
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
    if context == "pr":
        checked = _verified_pr_version(
            root, sha.lower(), base_sha.lower() if isinstance(base_sha, str) else base_sha,
            candidate, version, tags, names,
        )
        if checked is not None:
            return checked
    elif context != "release" or base_sha is not None:
        raise GuardError("release_identity_guard: invalid scoped identity context.")
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
    p.add_argument("--context", choices=("release", "pr"), default="release")
    p.add_argument("--base-sha")
    a=p.parse_args()
    try:
        candidate,latest=validate(Path(a.repo_root).resolve(),a.pr_title,a.candidate_sha,context=a.context,base_sha=a.base_sha)
    except (GuardError,OSError) as e:
        print(f"::error::{e}"); return 1
    print(f"release_identity_guard: OK candidate={candidate} sha={a.candidate_sha.lower()} latest={latest}")
    return 0
if __name__=="__main__": raise SystemExit(main())
