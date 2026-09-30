#!/usr/bin/env python3
"""Rollout DR Condor: descriptor/evidencia fail-closed y cifrado cliente."""
from __future__ import annotations
import argparse, hashlib, json, os, re, subprocess, sys
from pathlib import Path

ALIAS=re.compile(r"controlbot:connection/[a-z0-9][a-z0-9._-]{1,63}\Z")
PRIVACY=re.compile(r"controlbot:privacy/[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}\Z")
OPAQUE=re.compile(r"[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\Z")
ARTIFACT=re.compile(r"[A-Za-z0-9][A-Za-z0-9._-]{0,127}\Z")
SHA=re.compile(r"[0-9a-f]{64}\Z")
SENSITIVE=re.compile(r"password|passwd|secret|token|api[-_]?key|private[-_]?key|dsn|bearer",re.I)
OPS={"upload","verify","materialize"}
ROOT=(Path.home()/".condor-recovery").resolve()

class RecoveryError(ValueError): pass

def safe_ref(value:str,pattern:re.Pattern[str]=OPAQUE)->str:
    if not pattern.fullmatch(value) or ".." in value or "://" in value or SENSITIVE.search(value):
        raise RecoveryError("recovery_input_invalid")
    return value

def confined(name:str, *, existing:bool)->Path:
    if not ARTIFACT.fullmatch(name): raise RecoveryError("recovery_path_invalid")
    candidate=ROOT/name
    if candidate.is_symlink(): raise RecoveryError("recovery_path_invalid")
    if existing:
        try: actual=candidate.resolve(strict=True)
        except OSError as exc: raise RecoveryError("recovery_path_invalid") from exc
        if actual.parent!=ROOT or not actual.is_file(): raise RecoveryError("recovery_path_invalid")
        return actual
    if candidate.exists(): raise RecoveryError("recovery_path_invalid")
    return candidate

def digest(path:Path)->str:
    h=hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda:stream.read(1024*1024),b""): h.update(chunk)
    return h.hexdigest()

def canonical_sha(value:dict)->str:
    payload=json.dumps(value,sort_keys=True,separators=(",",":"),ensure_ascii=False).encode()
    return hashlib.sha256(payload).hexdigest()

def descriptor(operation:str,checksum:str,object_ref:str,connection_ref:str)->dict:
    if operation not in OPS or not SHA.fullmatch(checksum): raise RecoveryError("recovery_input_invalid")
    safe_ref(object_ref); safe_ref(connection_ref,ALIAS)
    base={"version":1,"project":"condor","provider":"object_storage","role":"primary_offsite",
          "operation":operation,"namespace":"recovery:condor","object_ref":object_ref,
          "checksum_sha256":checksum,"idempotency_key":"condor:dr:"+checksum,
          "authority":"unchanged","execute":False}
    return {"capability":"recovery.object-storage."+operation,"connection_ref":connection_ref,
            "descriptor":{**base,"descriptor_id":canonical_sha(base)}}

def crypt(source:str,target:str,decrypt:bool=False)->None:
    src=confined(source,existing=True); dst=confined(target,existing=False)
    if not os.environ.get("CONDOR_RECOVERY_ENCRYPTION_PASSPHRASE"):
        raise RecoveryError("recovery_crypto_unavailable")
    args=["/usr/bin/openssl","enc","-aes-256-cbc","-pbkdf2","-iter","200000","-md","sha256",
          "-pass","env:CONDOR_RECOVERY_ENCRYPTION_PASSPHRASE"]
    if decrypt: args.append("-d")
    args+=["-in",str(src),"-out",str(dst)]
    try: subprocess.run(args,check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=300)
    except (OSError,subprocess.SubprocessError) as exc: raise RecoveryError("recovery_crypto_failed") from exc
    os.chmod(dst,0o600)

def evidence(name:str,checksum:str)->dict:
    raw=json.loads(confined(name,existing=True).read_text(encoding="utf-8"))
    keys={"capability","operation","descriptor_id","object_ref","checksum_sha256","immutable_version_ref","evidence_ref"}
    if not isinstance(raw,dict) or set(raw)!=keys or raw.get("checksum_sha256")!=checksum:
        raise RecoveryError("recovery_evidence_invalid")
    for key in ("descriptor_id","checksum_sha256"):
        if not SHA.fullmatch(str(raw[key])): raise RecoveryError("recovery_evidence_invalid")
    for key in ("object_ref","immutable_version_ref","evidence_ref"): safe_ref(str(raw[key]))
    return raw

def report(args:argparse.Namespace)->dict:
    rows=[evidence(p,args.checksum) for p in (args.upload_evidence,args.verify_evidence,args.materialize_evidence)]
    if [r["operation"] for r in rows]!=["upload","verify","materialize"]: raise RecoveryError("recovery_evidence_invalid")
    if len({r["object_ref"] for r in rows})!=1 or len({r["immutable_version_ref"] for r in rows})!=1:
        raise RecoveryError("recovery_evidence_invalid")
    if not (args.backup_created_at<=args.offsite_verified_at<=args.restore_started_at<=args.restore_finished_at<=args.observed_at):
        raise RecoveryError("recovery_timing_invalid")
    rpo=args.offsite_verified_at-args.backup_created_at; rto=args.restore_finished_at-args.restore_started_at
    age=args.observed_at-args.restore_finished_at
    if min(rpo,rto,age,args.rpo_target,args.rto_target,args.freshness_target)<0:
        raise RecoveryError("recovery_timing_invalid")
    freshness="fresh" if age<=args.freshness_target else "stale"
    status="VERIFIED_WITHIN_TARGETS" if freshness=="fresh" and rpo<=args.rpo_target and rto<=args.rto_target else "DEGRADED"
    return {"version":1,"project":"condor","status":status,"freshness":freshness,
            "checksum_sha256":args.checksum,"rpo_observed_seconds":rpo,"rto_observed_seconds":rto,
            "freshness_age_seconds":age,"observed_at":args.observed_at,
            "backup_created_at":args.backup_created_at,"offsite_verified_at":args.offsite_verified_at,
            "restore_started_at":args.restore_started_at,"restore_finished_at":args.restore_finished_at,
            "immutable_version_ref":rows[0]["immutable_version_ref"],
            "evidence_refs":[r["evidence_ref"] for r in rows]}

def parser()->argparse.ArgumentParser:
    p=argparse.ArgumentParser(); sub=p.add_subparsers(dest="cmd",required=True)
    x=sub.add_parser("preflight"); x.add_argument("--connection-ref",required=True); x.add_argument("--privacy-ref",required=True)
    x=sub.add_parser("descriptor"); x.add_argument("--operation",choices=sorted(OPS),required=True); x.add_argument("--checksum",required=True); x.add_argument("--object-ref",required=True); x.add_argument("--connection-ref",required=True)
    for name in ("encrypt","decrypt"):
        x=sub.add_parser(name); x.add_argument("--source",required=True); x.add_argument("--target",required=True)
    x=sub.add_parser("checksum"); x.add_argument("--artifact",required=True)
    x=sub.add_parser("report")
    for name in ("checksum","upload-evidence","verify-evidence","materialize-evidence"): x.add_argument("--"+name,required=True)
    for name in ("backup-created-at","offsite-verified-at","restore-started-at","restore-finished-at","observed-at","rpo-target","rto-target","freshness-target"): x.add_argument("--"+name,type=int,required=True)
    return p

def main()->int:
    try:
        a=parser().parse_args()
        if a.cmd=="preflight": safe_ref(a.connection_ref,ALIAS); safe_ref(a.privacy_ref,PRIVACY); out={"status":"READY","connection_ref":a.connection_ref,"privacy_ref":a.privacy_ref}
        elif a.cmd=="descriptor": out=descriptor(a.operation,a.checksum,a.object_ref,a.connection_ref)
        elif a.cmd=="checksum": out={"checksum_sha256":digest(confined(a.artifact,existing=True))}
        elif a.cmd in {"encrypt","decrypt"}: crypt(a.source,a.target,a.cmd=="decrypt"); out={"status":"OK"}
        else: out=report(a)
        print(json.dumps(out,separators=(",",":"),sort_keys=True)); return 0
    except (RecoveryError,ValueError,OSError,json.JSONDecodeError):
        print("recovery_production_failed",file=sys.stderr); return 1

if __name__=="__main__": raise SystemExit(main())
