"""Reparación puntual del lock firmado de #1685; no concede ninguna V3.

Únicamente el propietario puede invocarla desde el workflow protegido; el
repo, el nonce, la rama y su árbol deben coincidir con evidencias históricas.
"""
from __future__ import annotations

from datetime import datetime, timezone
from pathlib import Path
from urllib.error import HTTPError
import json
import os
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
import recuperar_lock724 as shared

REPO = "pl0n3r/Condor"
ISSUE = 1685
NONCE = "d24837972852295b8f221110491d72f01ffad88a"
PARENT = "ee3b97f9df33688d52af737c47080b72abc66a5f"
TREE = "908c791e40054fc86cc5f9076e86f3050ee1f48a"
MESSAGE = "lock coordinacion Issue #1685 30d5bf5e-7a44-4842-92fa-fc39e5b30bdc"
LOCK_REF = "refs/heads/coordinacion/lock-issue-1685"
LOCK_API = f"/repos/{REPO}/git/ref/heads/coordinacion/lock-issue-1685"
BRANCH = "trabajo/issue-1685"
BRANCH_API = f"/repos/{REPO}/git/ref/heads/{BRANCH}"
WORKFLOW = f"{REPO}/.github/workflows/recuperar-lock1685.yml@refs/heads/main"
Denied = shared.Denied
ensure = shared.demand


def snapshot(api, now: datetime, run_id: str) -> str:
    """Un conjunto completo de guardas; se exige de nuevo antes del CAS."""
    shared.valid_global(api)
    freeze = api.get("/repos/pl0n3r/Factory/issues/921")
    ensure(type(freeze) is dict and freeze.get("state") == "closed",
           "release congelado")
    item = api.get(f"/repos/{REPO}/issues/{ISSUE}")
    labels = item.get("labels") if type(item) is dict else None
    visible = ([x.get("name") for x in labels if type(x) is dict]
               if type(labels) is list else [])
    ensure(type(item) is dict and item.get("state") == "open"
           and visible.count("estado: bloqueado") == 1
           and len([x for x in visible if x.startswith("estado:")]) == 1,
           "issue no bloqueado o estado ambiguo")

    # El registro V3 se toma solo del bot oficial y se lee hasta la última
    # página; un comentario humano no acredita ni revoca una reserva.
    last_receipt = None
    for page in range(1, 11):
        batch = api.get(f"/repos/{REPO}/issues/{ISSUE}/comments?per_page=100&page={page}")
        ensure(type(batch) is list, "historial de reservas ilegible")
        for entry in batch:
            ensure(type(entry) is dict, "comentario de reserva ilegible")
            receipt = shared._lease_from_bot_comment(entry)
            if receipt is not None:
                last_receipt = receipt
        if len(batch) < 100:
            break
    else:
        raise Denied("historial V3 truncado")
    ensure(last_receipt is None or (
           type(last_receipt) is dict and last_receipt.get("version") == 3
           and last_receipt.get("active") is False),
           "reserva V3 activa o imposible de autenticar")

    try:
        work = api.get(BRANCH_API)
    except HTTPError as exc:
        raise Denied("rama huérfana desaparecida") from exc
    ensure(type(work) is dict and type(work.get("object")) is dict
           and work["object"].get("sha") == PARENT,
           "rama del issue contiene commits o cambió de parent")
    prs = api.get(f"/repos/{REPO}/pulls?state=open&per_page=100")
    ensure(type(prs) is list and len(prs) < 100,
           "inventario PR incompleto")
    ensure(all(type(pr) is dict and type(pr.get("head")) is dict
               and pr["head"].get("ref") != BRANCH for pr in prs),
           "PR vivo asociado a la rama huérfana")
    shared.no_live_coordination(api, run_id)

    ref = api.get(LOCK_API)
    ensure(type(ref) is dict and type(ref.get("object")) is dict
           and ref["object"].get("sha") == NONCE,
           "lock reemplazado o ausente")
    signed = api.get(f"/repos/{REPO}/git/commits/{NONCE}")
    ensure(type(signed) is dict and signed.get("sha") == NONCE
           and signed.get("message") == MESSAGE,
           "identidad SHA/mensaje incorrecta")
    parents = signed.get("parents")
    ensure(type(parents) is list and len(parents) == 1
           and type(parents[0]) is dict and parents[0].get("sha") == PARENT,
           "parent inesperado")
    parent = api.get(f"/repos/{REPO}/git/commits/{PARENT}")
    tree = signed.get("tree")
    ensure(type(tree) is dict and tree.get("sha") == TREE
           and type(parent) is dict and type(parent.get("tree")) is dict
           and parent["tree"].get("sha") == TREE,
           "el commit no era un lock inerte")
    author = signed.get("author")
    committer = signed.get("committer")
    verify = signed.get("verification")
    ensure(type(author) is dict
           and author.get("name") == "github-actions[bot]"
           and author.get("email") == "41898282+github-actions[bot]@users.noreply.github.com",
           "autor no confiable")
    ensure(type(committer) is dict and committer.get("name") == "GitHub"
           and committer.get("email") == "noreply@github.com",
           "firmante no confiable")
    ensure(type(verify) is dict and verify.get("verified") is True
           and verify.get("reason") == "valid",
           "firma no validada")
    ensure(isinstance(now, datetime) and now.tzinfo is not None,
           "reloj inválido")
    for identity in (author, committer):
        stamp = identity.get("date")
        ensure(type(stamp) is str, "fecha ausente")
        try:
            created = datetime.fromisoformat(stamp.replace("Z", "+00:00"))
        except ValueError as exc:
            raise Denied("fecha malformada") from exc
        ensure(created.tzinfo is not None
               and (now - created).total_seconds() >= 3600,
               "lock reciente, futuro o indatable")
    identity = api.get(f"/repos/{REPO}")
    ensure(type(identity) is dict and identity.get("name") == "Condor"
           and type(identity.get("owner")) is dict
           and identity["owner"].get("login") == "pl0n3r"
           and type(identity.get("node_id")) is str
           and bool(identity["node_id"]),
           "identidad del repositorio ilegible")
    return identity["node_id"]


def recover(api, now: datetime, run_id: str) -> None:
    first = snapshot(api, now, run_id)
    ensure(first == snapshot(api, now, run_id),
           "identidad cambió durante la revisión")
    # Una única mutación CAS: si otro proceso cambió el lock, falla sin tocarlo.
    answer = api.post("/graphql", {
        "query": "mutation($input:UpdateRefsInput!){updateRefs(input:$input){clientMutationId}}",
        "variables": {"input": {"repositoryId": first, "refUpdates": [{
            "name": LOCK_REF, "beforeOid": NONCE, "afterOid": "0" * 40,
            "force": True,
        }]}}
    })
    ensure(type(answer) is dict and not answer.get("errors")
           and type(answer.get("data")) is dict
           and type(answer["data"].get("updateRefs")) is dict,
           "CAS denegado o respuesta incierta")
    try:
        api.get(LOCK_API)
    except HTTPError as exc:
        ensure(exc.code == 404, "confirmación de borrado no válida")
    else:
        raise Denied("el lock sigue ocupado")
    api.post(f"/repos/{REPO}/issues/{ISSUE}/comments", {
        "body": f"CAS verificado: solo {LOCK_REF}@{NONCE} eliminado y GET=404. "
                "Sin V3 activa ni PR; la rama huérfana sigue en el parent original. "
                "NO SE CONCEDE RESERVA. Ejecutar adopción canónica y esperar recibo BOT V3."
    })


def main() -> int:
    try:
        ensure(os.getenv("GITHUB_REPOSITORY") == REPO
               and os.getenv("GITHUB_ACTOR") == "pl0n3r"
               and os.getenv("GITHUB_EVENT_NAME") == "issue_comment"
               and os.getenv("GITHUB_REF") == "refs/heads/main"
               and os.getenv("GITHUB_WORKFLOW_REF") == WORKFLOW,
               "workflow/actor no autorizado")
        data = json.loads(Path(os.environ["GITHUB_EVENT_PATH"]).read_text("utf-8"))
        ensure(type(data) is dict and data.get("action") == "created",
               "evento incorrecto")
        issue, comment, sender = (
            data.get("issue"), data.get("comment"), data.get("sender"))
        ensure(type(issue) is dict and issue.get("number") == ISSUE
               and issue.get("pull_request") is None
               and type(comment) is dict
               and comment.get("body") == "/limpiar-lock-1685"
               and type(comment.get("user")) is dict
               and comment["user"].get("login") == "pl0n3r"
               and comment.get("author_association") == "OWNER"
               and type(sender) is dict and sender.get("login") == "pl0n3r",
               "comando propietario no autorizado")
        token = os.getenv("GITHUB_TOKEN")
        run_id = os.getenv("GITHUB_RUN_ID")
        ensure(bool(token) and type(run_id) is str and run_id.isdigit(),
               "runner o token no verificable")
        recover(shared.GitHubApi(token), datetime.now(timezone.utc), run_id)
        return 0
    except (Denied, KeyError, OSError, ValueError, TypeError, AttributeError):
        print("RECOVERY DENIED: autoridad o evidencia insuficiente", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
