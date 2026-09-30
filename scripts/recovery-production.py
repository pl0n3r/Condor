#!/usr/bin/env python3
"""Rollout DR Condor: boundary Recovery fail-closed; Factory #330 evalúa el drill."""
from __future__ import annotations
import argparse, hashlib, json, os, re, subprocess, sys
from datetime import datetime, timezone
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
        value=raw[key]
        if not isinstance(value,str) or not SHA.fullmatch(value):
            raise RecoveryError("recovery_evidence_invalid")
    for key in ("object_ref","immutable_version_ref","evidence_ref"):
        value=raw[key]
        if not isinstance(value,str):
            raise RecoveryError("recovery_evidence_invalid")
        safe_ref(value)
    return raw

def iso8601(epoch:int)->str:
    if not isinstance(epoch,int) or epoch<0: raise RecoveryError("recovery_timing_invalid")
    try: return datetime.fromtimestamp(epoch,timezone.utc).isoformat().replace("+00:00","Z")
    except (OverflowError,OSError,ValueError) as exc: raise RecoveryError("recovery_timing_invalid") from exc

def true_flag(value:str)->bool:
    if value!="true": raise RecoveryError("recovery_check_invalid")
    return True

def drill_inputs(args:argparse.Namespace)->dict:
    if not SHA.fullmatch(args.checksum): raise RecoveryError("recovery_input_invalid")
    run_id=safe_ref(args.run_id)
    rows=[evidence(p,args.checksum) for p in (args.upload_evidence,args.verify_evidence,args.materialize_evidence)]
    if [r["operation"] for r in rows]!=["upload","verify","materialize"]: raise RecoveryError("recovery_evidence_invalid")
    if len({r["object_ref"] for r in rows})!=1 or len({r["immutable_version_ref"] for r in rows})!=1:
        raise RecoveryError("recovery_evidence_invalid")
    if not (args.backup_created_at<=args.offsite_verified_at<=args.incident_at<=args.started_at<=args.completed_at):
        raise RecoveryError("recovery_timing_invalid")
    if args.rpo_target<60 or args.rto_target<60 or args.rpo_target%60 or args.rto_target%60:
        raise RecoveryError("recovery_target_invalid")

    health=true_flag(args.health_ok); smoke=true_flag(args.smoke_ok); integrity=true_flag(args.integrity_ok)
    object_ref=rows[0]["object_ref"]; immutable=rows[0]["immutable_version_ref"]
    refs=[r["evidence_ref"] for r in rows]
    backup_id=safe_ref("backup:condor:"+run_id)
    target_ref=safe_ref("sandbox:condor:"+run_id)
    manifest={
        "version":1,"project":"condor",
        "target":{"rpo_minutes":args.rpo_target//60,"rto_minutes":args.rto_target//60},
        "protection":{"copies":3,"media_types":2,"offsite_copies":1,"immutable_copies":1,"undetected_restore_failures":0},
        "retention":{"hourly":24,"daily":7,"weekly":8,"monthly":12},
        "sources":{"database":"REQUIRED","media":"NOT_APPLICABLE","repository":"NOT_APPLICABLE"},
        "offsite":{"object_storage":"REQUIRED","cold_copy":"NOT_APPLICABLE"},
        "encryption":{"required":True,"key_material":"EXTERNAL_ONLY"},
        "restore_drill":{"cadence_days":7},
    }
    verified_backup={
        "version":1,"status":"VERIFIED","project":"condor","source":"database",
        "backup_id":backup_id,"checksum_sha256":args.checksum,"encrypted":True,
        "immutable_version_ref":immutable,
        "destinations":[{"provider":"object_storage","role":"primary_offsite","object_ref":object_ref,"verified":True}],
        "created_at":iso8601(args.backup_created_at),"verified_at":iso8601(args.offsite_verified_at),
        "freshness_target_seconds":args.rpo_target,"evidence_refs":refs,
        "authority":"unchanged","execute":False,
    }
    drill_evidence={
        "backup_id":backup_id,"checksum_sha256":args.checksum,
        "health_ok":health,"smoke_ok":smoke,"integrity_ok":integrity,
        "incident_at":iso8601(args.incident_at),"started_at":iso8601(args.started_at),
        "completed_at":iso8601(args.completed_at),
        "evidence_refs":refs+[
            safe_ref("gha:"+run_id+":integrity"),
            safe_ref("gha:"+run_id+":health"),
            safe_ref("gha:"+run_id+":smoke"),
        ],
    }
    return {
        "manifest":manifest,
        "verified_backup":verified_backup,
        "target":{"kind":"disposable","target_ref":target_ref},
        "evidence":drill_evidence,
        "protection_latency_seconds":args.offsite_verified_at-args.backup_created_at,
    }

def parser()->argparse.ArgumentParser:
    p=argparse.ArgumentParser(); sub=p.add_subparsers(dest="cmd",required=True)
    x=sub.add_parser("preflight"); x.add_argument("--connection-ref",required=True); x.add_argument("--privacy-ref",required=True)
    x=sub.add_parser("descriptor"); x.add_argument("--operation",choices=sorted(OPS),required=True); x.add_argument("--checksum",required=True); x.add_argument("--object-ref",required=True); x.add_argument("--connection-ref",required=True)
    for name in ("encrypt","decrypt"):
        x=sub.add_parser(name); x.add_argument("--source",required=True); x.add_argument("--target",required=True)
    x=sub.add_parser("checksum"); x.add_argument("--artifact",required=True)
    x=sub.add_parser("drill-inputs")
    for name in ("checksum","upload-evidence","verify-evidence","materialize-evidence","run-id","health-ok","smoke-ok","integrity-ok"):
        x.add_argument("--"+name,required=True)
    for name in ("backup-created-at","offsite-verified-at","incident-at","started-at","completed-at","rpo-target","rto-target"):
        x.add_argument("--"+name,type=int,required=True)
    return p

def main()->int:
    try:
        a=parser().parse_args()
        if a.cmd=="preflight": safe_ref(a.connection_ref,ALIAS); safe_ref(a.privacy_ref,PRIVACY); out={"status":"READY","connection_ref":a.connection_ref,"privacy_ref":a.privacy_ref}
        elif a.cmd=="descriptor": out=descriptor(a.operation,a.checksum,a.object_ref,a.connection_ref)
        elif a.cmd=="checksum": out={"checksum_sha256":digest(confined(a.artifact,existing=True))}
        elif a.cmd in {"encrypt","decrypt"}: crypt(a.source,a.target,a.cmd=="decrypt"); out={"status":"OK"}
        else: out=drill_inputs(a)
        print(json.dumps(out,separators=(",",":"),sort_keys=True)); return 0
    except (ValueError,OSError):
        print("recovery_production_failed",file=sys.stderr); return 1

if __name__=="__main__": raise SystemExit(main())
