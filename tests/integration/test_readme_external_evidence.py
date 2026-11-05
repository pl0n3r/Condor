"""#1556: contratos de recibos externos; fixtures offline, nunca evidencia live.

Los consumidores han de obtener las respuestas REST originales de GitHub y el
recibo de observer por canales autorizados. Estas pruebas NO llaman a redes.
"""
from __future__ import annotations

import copy
import datetime as dt
import json
import re
import unittest
from pathlib import Path
from urllib.parse import parse_qs, unquote, urlsplit

ROOT = Path(__file__).resolve().parents[2]
README = ROOT / "README.md"
REPO = "pl0n3r/Condor"
SHA = "a" * 40
UTC = dt.timezone.utc

QUERY = {
    "NOW_RESERVED": 'is:issue is:open label:"estado: reservado"',
    "NOW_REVIEW": 'is:issue is:open label:"estado: en revisión"',
    "NEXT": 'is:issue is:open label:"prioridad: crítica" label:"estado: disponible"',
    "BLOCKED": 'is:issue is:open label:"estado: bloqueado"',
}


class EvidenceError(ValueError):
    """No se acepta una fuente ausente, mezclada o falsamente completa."""


def require(condition: bool, reason: str) -> None:
    if not condition:
        raise EvidenceError(reason)


def utc_time(value: object) -> dt.datetime:
    require(isinstance(value, str), "timestamp missing")
    try:
        stamp = dt.datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as error:
        raise EvidenceError("timestamp invalid") from error
    require(stamp.tzinfo is not None, "timestamp unqualified")
    return stamp.astimezone(UTC)


def fresh(value: object, now: dt.datetime, maximum_age: int = 900) -> None:
    age = (now - utc_time(value)).total_seconds()
    require(0 <= age <= maximum_age, "timestamp stale or future")


def labels(row: dict) -> set[str]:
    result = set()
    for tag in row.get("labels", []):
        require(isinstance(tag, dict) and isinstance(tag.get("name"), str),
                "label schema")
        result.add(tag["name"])
    return result


def readme_links() -> dict[str, str]:
    readme = README.read_text(encoding="utf-8")
    links = re.findall(r"https://github\.com/pl0n3r/Condor/issues\?q=([^)\s]+)", readme)
    result: dict[str, str] = {}
    for link in links:
        text = unquote(link.replace("+", " "))
        for category, query in QUERY.items():
            if text == query:
                require(category not in result, "duplicate queue link")
                result[category] = text
    require(set(result) == set(QUERY), "queue link missing or changed")
    require("actions/workflows/ci.yml/badge.svg?branch=main" in readme,
            "CI badge no longer targets main")
    require("https://img.shields.io/github/v/release/pl0n3r/Condor" in readme,
            "release badge removed")
    return result


def queue_receipt(pages: list[dict], category: str, now: dt.datetime) -> dict:
    """Paginación exhaustiva, fuente exacta y sin equiparar etiquetas con V3."""
    require(category in QUERY and bool(pages), "query or pages absent")
    total: int | None = None
    seen: set[int] = set()
    per_page: int | None = None
    for index, page in enumerate(pages, 1):
        url = urlsplit(page.get("url", ""))
        params = parse_qs(url.query)
        require(url.scheme == "https" and url.netloc == "api.github.com"
                and url.path == "/search/issues", "not GitHub REST search")
        require(params.get("q") == [f"repo:{REPO} {QUERY[category]}"],
                "wrong queue query")
        require(params.get("page") == [str(index)], "page not contiguous")
        number = params.get("per_page", [""])
        require(len(number) == 1 and number[0].isdigit()
                and 1 <= int(number[0]) <= 100, "per_page invalid")
        if per_page is None:
            per_page = int(number[0])
        require(int(number[0]) == per_page, "per_page changed")
        fresh(page.get("fetched_at"), now)
        data = page.get("response")
        require(isinstance(data, dict), "no original JSON response")
        count = data.get("total_count")
        require(type(count) is int and 0 <= count <= 1000, "total_count invalid")
        require(data.get("incomplete_results") is False, "search incomplete")
        require(isinstance(data.get("items"), list), "items invalid")
        require(len(data["items"]) <= per_page, "page overflow")
        if total is None:
            total = count
        require(total == count, "pages disagree on count")
        for item in data["items"]:
            require(isinstance(item, dict) and item.get("state") == "open"
                    and type(item.get("number")) is int, "issue invalid")
            require(item.get("html_url") == f"https://github.com/{REPO}/issues/{item["number"]}", "foreign issue")
            require(item["number"] not in seen, "repeated issue")
            seen.add(item["number"])
            present = labels(item)
            required = {
                "NOW_RESERVED": {"estado: reservado"},
                "NOW_REVIEW": {"estado: en revisión"},
                "NEXT": {"prioridad: crítica", "estado: disponible"},
                "BLOCKED": {"estado: bloqueado"},
            }[category]
            require(required <= present, "issue does not satisfy query")
        # Caller must retain the actual Link header; no guessed next page.
        has_more = page.get("next_url")
        if index < len(pages):
            require(has_more == pages[index]["url"], "Link next mismatch")
        else:
            require(has_more is None, "unread next page")
    require(total is not None and len(seen) == total,
            "partial pagination is not complete external evidence")
    return {"coverage": "PAGINATED_SNAPSHOT", "count":total,
            "lease_authority": False, "production_authority": False}


def lease_marker(issue: dict, comments: list[dict]) -> dict:
    """Filtrado por etiquetas nunca acredita /tomar ni depends_on."""
    require(issue.get("html_url", "").startswith(f"https://github.com/{REPO}/issues/"),
            "wrong issue source")
    number = issue.get("number")
    require(type(number) is int and number > 0, "issue number invalid")
    markers = []
    for item in comments:
        require(item.get("issue_number") == number
                and item.get("source") == "github-rest", "comment provenance absent")
        marker = re.search(r"<!-- condor-reserva (\{[^\n]*\}) -->",
                           item.get("body", ""))
        if marker:
            require(item.get("author") == "github-actions[bot]",
                    "reserve marker not from official bot")
            try:
                data = json.loads(marker.group(1))
            except ValueError as error:
                raise EvidenceError("bad reservation JSON") from error
            require(data.get("version") == 3
                    and data.get("owner") == "pl0n3r"
                    and re.fullmatch(r"[0-9a-f-]{36}",
                                     str(data.get("reservation_id", ""))) is not None,
                    "invalid V3 contract")
            markers.append(data)
    require(bool(markers) and markers[-1].get("active") is True,
            "V3 absent/revoked; label is not proof")
    return {"reservation_id":markers[-1]["reservation_id"],
            "branch":markers[-1].get("branch"),
            "depends_on":markers[-1].get("task_depends_on", [])}


def badge_receipt(receipt: dict, now: dt.datetime) -> str:
    url = urlsplit(receipt.get("url", ""))
    require(url.scheme == "https", "non-TLS badge")
    allowed = (url.netloc == "github.com"
               and url.path == "/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg"
               and parse_qs(url.query).get("branch") == ["main"]) or (
                   url.netloc == "img.shields.io"
                   and url.path == "/github/v/release/pl0n3r/Condor")
    require(allowed, "badge not bound to README source")
    fresh(receipt.get("fetched_at"), now)
    require(receipt.get("status") == 200
            and receipt.get("content_type", "").startswith("image/svg+xml"),
            "badge unavailable")
    return "BADGE_HTTP_ONLY"


def browser_contract(build: dict, browser: dict, twig: dict,
                     sha: str, now: dt.datetime) -> str:
    require(re.fullmatch(r"[a-f0-9]{40}", sha) is not None, "SHA invalid")
    for receipt, expected in ((build, "admin_build"), (browser, "chromium"),
                              (twig, "twig_ssr")):
        require(receipt.get("kind") == expected and receipt.get("sha") == sha,
                "wrong build/browser/SSR identity")
        require(receipt.get("environment") == "isolated-fixture"
                and receipt.get("status") == "success", "not isolated success")
        fresh(receipt.get("completed_at"), now)
        require(re.fullmatch(r"[a-f0-9]{64}", str(receipt.get("artifact_sha256", "")))
                is not None, "artifact identity missing")
    require(browser.get("origin") in ("http://127.0.0.1", "http://localhost")
            and browser.get("denied_case") is True,
            "browser host or negative scenario invalid")
    require(twig.get("escaped_case") is True
            and twig.get("status_code") == 200, "SSR not exercised safely")
    return "SYNTHETIC_CONTRACT_ONLY"


def observer_contract(receipt: dict | None, sha: str, version: str,
                      now: dt.datetime, authenticated: bool = False) -> str:
    """No aceptar autoafirmaciones de producción de fixtures o CI."""
    if not authenticated or receipt is None:
        return "UNKNOWN"
    require(receipt.get("source") == "trusted-observer"
            and receipt.get("state") == "VALIDATED_IN_PRODUCTION"
            and receipt.get("terminal") is True, "observer not terminal")
    require(receipt.get("sha") == sha and receipt.get("version") == version,
            "observer not exact release identity")
    fresh(receipt.get("observed_at"), now)
    require(receipt.get("pending") == [] and receipt.get("critical") == [],
            "observer has unresolved evidence")
    require(re.fullmatch(r"[a-f0-9]{64}", str(receipt.get("artifact_sha256", "")))
            is not None, "observer artifact not identified")
    require(re.fullmatch(r"https://github\.com/pl0n3r/Condor/actions/runs/[0-9]+",
                         str(receipt.get("run_url", ""))) is not None,
            "observer run not traceable")
    # Authentication is granted ONLY by an external verifier of the official
    # workflow run, artifact bytes, observer signature and D-043/D-059 gates.
    # Offline fixtures never supply it; no production claim is made here.
    return "STRUCTURALLY_ELIGIBLE_NOT_PRODUCTION_PROOF"


class ReadmeExternalEvidenceTests(unittest.TestCase):
    NOW = dt.datetime(2026, 11, 5, 22, 30, tzinfo=UTC)
    WHEN = "2026-11-05T22:29:00Z"

    def page(self, page: int, numbers: list[int], total: int = 2,
             next_url: str | None = None) -> dict:
        from urllib.parse import urlencode
        q = f"repo:{REPO} {QUERY['BLOCKED']}"
        url = "https://api.github.com/search/issues?" + urlencode(
            {"q": q, "per_page": 1, "page": page})
        return {"url": url, "fetched_at": self.WHEN,
                "response": {"total_count":total, "incomplete_results":False,
                             "items":[{"number":n, "state":"open",
                                       "html_url":f"https://github.com/{REPO}/issues/{n}",
                                       "labels":[{"name":"estado: bloqueado"}]}
                                      for n in numbers]},
                "next_url":next_url}

    def test_readme_badges_and_exact_queue_filters(self):
        self.assertEqual(readme_links(), QUERY)

    def test_git_live_search_requires_all_pages_and_timestamps(self):
        second = self.page(2, [102])
        first = self.page(1, [101], next_url=second["url"])
        self.assertEqual(queue_receipt([first, second], "BLOCKED", self.NOW)["count"], 2)
        with self.assertRaises(EvidenceError):
            queue_receipt([first], "BLOCKED", self.NOW)
        for tamper in ("incomplete", "foreign", "duplicate", "stale", "query"):
            pages = copy.deepcopy([first, second])
            if tamper == "incomplete": pages[0]["response"]["incomplete_results"] = True
            if tamper == "foreign": pages[1]["response"]["items"][0]["html_url"] = "https://github.com/other/repo/issues/102"
            if tamper == "duplicate": pages[1]["response"]["items"][0]["number"] = 101
            if tamper == "stale": pages[0]["fetched_at"] = "2026-11-01T00:00:00Z"
            if tamper == "query": pages[0]["url"] = pages[0]["url"].replace("page=1", "page=4")
            with self.subTest(tamper=tamper), self.assertRaises(EvidenceError):
                queue_receipt(pages, "BLOCKED", self.NOW)

    def test_label_is_not_authenticated_reservation_or_dependency(self):
        issue = {"number":1556, "html_url":f"https://github.com/{REPO}/issues/1556",
                 "labels":[{"name":"estado: reservado"}]}
        with self.assertRaises(EvidenceError):
            lease_marker(issue, [])
        marker = {"version":3, "owner":"pl0n3r", "active":True,
                  "branch":"trabajo/issue-1556",
                  "reservation_id":"616435c1-9886-4d6a-bad2-e64ca1728851",
                  "task_depends_on":[]}
        sample = {"issue_number":1556, "source":"github-rest",
                  "author":"github-actions[bot]",
                  "body":"<!-- condor-reserva " + json.dumps(marker) + " -->"}
        self.assertEqual(lease_marker(issue,[sample])["depends_on"], [])
        tampered=copy.deepcopy(sample)
        tampered["author"]="an-attacker"
        with self.assertRaises(EvidenceError):
            lease_marker(issue,[tampered])
        revoked=copy.deepcopy(sample)
        marker["active"]=False
        revoked["body"]="<!-- condor-reserva "+json.dumps(marker)+" -->"
        with self.assertRaises(EvidenceError):
            lease_marker(issue,[sample,revoked])

    def test_badge_http_does_not_prove_ci_success(self):
        badge={"url":"https://github.com/pl0n3r/Condor/actions/workflows/ci.yml/badge.svg?branch=main",
               "fetched_at":self.WHEN,"status":200,"content_type":"image/svg+xml"}
        self.assertEqual(badge_receipt(badge,self.NOW), "BADGE_HTTP_ONLY")
        with self.assertRaises(EvidenceError):
            badge_receipt({**badge,"url":badge["url"].replace("main","work-branch")},self.NOW)

    def test_browser_build_twig_need_isolated_receipts_and_negatives(self):
        def document(kind):
            return {"kind":kind,"sha":SHA,"environment":"isolated-fixture",
                    "status":"success","completed_at":self.WHEN,
                    "artifact_sha256":"b"*64}
        build,browser,twig=(document(k) for k in ("admin_build","chromium","twig_ssr"))
        browser.update(origin="http://127.0.0.1",denied_case=True)
        twig.update(escaped_case=True,status_code=200)
        self.assertEqual(browser_contract(build,browser,twig,SHA,self.NOW),
                         "SYNTHETIC_CONTRACT_ONLY")
        for key, value in (("origin","https://www.condorapp.com.co"),
                           ("denied_case",False),("sha","c"*40)):
            bad=copy.deepcopy(browser); bad[key]=value
            with self.subTest(key=key), self.assertRaises(EvidenceError):
                browser_contract(build,bad,twig,SHA,self.NOW)
        with self.assertRaises(EvidenceError):
            browser_contract(build,browser,{**twig,"escaped_case":False},SHA,self.NOW)

    def test_observer_requires_external_authentication_terminal_sha(self):
        observer={"source":"trusted-observer","state":"VALIDATED_IN_PRODUCTION",
                  "terminal":True,"sha":SHA,"version":"0.1.198",
                  "observed_at":self.WHEN,"pending":[],"critical":[],
                  "artifact_sha256":"b"*64,
                  "run_url":"https://github.com/pl0n3r/Condor/actions/runs/123"}
        self.assertEqual(observer_contract(observer,SHA,"0.1.198",self.NOW),
                         "UNKNOWN")
        self.assertEqual(observer_contract(None,SHA,"0.1.198",self.NOW),
                         "UNKNOWN")
        self.assertEqual(observer_contract(observer,SHA,"0.1.198",self.NOW,True),
                         "STRUCTURALLY_ELIGIBLE_NOT_PRODUCTION_PROOF")
        for key, value in (("sha","c"*40),("terminal",False),
                           ("pending",["backup"]),("observed_at","2025-01-01T00:00:00Z")):
            bad={**observer,key:value}
            with self.subTest(key=key), self.assertRaises(EvidenceError):
                observer_contract(bad,SHA,"0.1.198",self.NOW,True)


if __name__ == "__main__":
    unittest.main()
