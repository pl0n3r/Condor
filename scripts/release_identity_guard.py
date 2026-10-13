#!/usr/bin/env python3
from __future__ import annotations
import argparse,os,re,subprocess
from pathlib import Path

VERSION_RE=re.compile(r"""['"]version['"]\s*=>\s*['"](\d+\.\d+\.\d+)['"]""")
TITLE_RE=re.compile(r"\(V (\d+\.\d+\.\d+)\)$")
TAG_RE=re.compile(r"^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$")
SHA_RE=re.compile(r"^[0-9a-f]{40}$")

class GuardError(RuntimeError): pass

def sv(s):
    m=re.fullmatch(r"(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)",s)
    if not m: raise GuardError(f"release_identity_guard: invalid SemVer {s!r}.")
    return tuple(map(int,m.groups()))

_SAFE_GIT_COMMANDS = tuple(re.compile(pattern) for pattern in (
    r"tag\x00--list",
    r"rev-parse\x00refs/remotes/origin/main",
    r"cat-file\x00-t\x00[0-9a-f]{40}\^\{commit\}",
    r"cat-file\x00-t\x00refs/tags/v[0-9]+\.[0-9]+\.[0-9]+",
    r"merge-base\x00[0-9a-f]{40}\x00[0-9a-f]{40}",
    r"show\x00[0-9a-f]{40}:config/version\.php",
    r"diff\x00--name-only\x00[0-9a-f]{40}\x00[0-9a-f]{40}\x00--\x00config/version\.php",
    r"rev-list\x00-n\x001\x00v[0-9]+\.[0-9]+\.[0-9]+",
))


def git(root,*args):
    # Only the read-only operations used by this identity guard may reach Git.
    # Reject unexpected verbs, flags, non-string arguments and embedded NULs.
    if (not args or any(type(a) is not str or "\x00" in a for a in args)
            or not any(rule.fullmatch("\x00".join(args)) for rule in _SAFE_GIT_COMMANDS)):
        raise GuardError("release_identity_guard: unapproved Git command arguments.")
    # Allowlisted verbs/prefixes are constructed here, never copied from
    # untrusted CLI arguments. Pattern validation above also rejects options
    # masquerading as SHA, tag or file-path operands.
    verb=args[0]
    prefixes={
        "tag": ["git", "tag", "--list"],
        "rev-parse": ["git", "rev-parse", "refs/remotes/origin/main"],
        "cat-file": ["git", "cat-file", "-t"],
        "merge-base": ["git", "merge-base"],
        "show": ["git", "show"],
        "diff": ["git", "diff", "--name-only"],
        "rev-list": ["git", "rev-list", "-n", "1"],
    }
    suffixes={
        "tag": [],
        "rev-parse": [],
        "cat-file": [args[2]] if verb=="cat-file" else [],
        "merge-base": list(args[1:]) if verb=="merge-base" else [],
        "show": [args[1]] if verb=="show" else [],
        "diff": [args[2],args[3],"--","config/version.php"] if verb=="diff" else [],
        "rev-list": [args[3]] if verb=="rev-list" else [],
    }
    # Repository-location overrides from a parent process must not bypass cwd.
    # The guard only performs read-only Git operations and needs no GIT_* settings.
    clean_env = {key: value for key, value in os.environ.items()
                 if not key.startswith("GIT_")}
    p=subprocess.run(prefixes[verb]+suffixes[verb],cwd=root,text=True,
                     capture_output=True,check=False,env=clean_env)
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
    # An unchanged-version PR may be older than main but still merge cleanly.
    # A new release identity, by contrast, must start from current main.
    main_is_parent = git(root, "merge-base", base_sha, sha) == base_sha
    original = VERSION_RE.findall(git(root, "show", f"{base_sha}:config/version.php"))
    if len(original) != 1:
        raise GuardError("release_identity_guard: invalid trusted base version.")
    base_version = original[0]
    if sv(version) < sv(base_version):
        raise GuardError("release_identity_guard: PR version regresses main.")
    if version != base_version:
        if not main_is_parent:
            raise GuardError("release_identity_guard: new-version PR head is not based on current main.")
        return None
    common_parent = git(root, "merge-base", base_sha, sha)
    if git(root, "diff", "--name-only", common_parent, sha, "--", "config/version.php"):
        raise GuardError("release_identity_guard: same-version PR modifies config/version.php.")
    if candidate not in names:
        raise GuardError("release_identity_guard: unchanged PR version lacks authentic tag.")
    if git(root, "cat-file", "-t", f"refs/tags/{candidate}") != "tag":
        raise GuardError("release_identity_guard: base tag is not annotated.")
    # The annotated latest release must be a common ancestor of both
    # authenticated main and the PR head. This permits parallel code-only
    # merges without permitting tag retargeting, stale pre-release heads or
    # an unrelated history.
    tag_sha = git(root, "rev-list", "-n", "1", candidate)
    if (git(root, "merge-base", tag_sha, base_sha) != tag_sha
            or git(root, "merge-base", tag_sha, sha) != tag_sha):
        raise GuardError("release_identity_guard: release tag is not common trusted ancestry.")
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
