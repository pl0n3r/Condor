import json,subprocess,sys,tempfile,unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0,str(ROOT))

from scripts.dependency_pr_promotion import (
    PromotionError, RUNTIME_DIR, build_plan, contract_fingerprint,
    ensure_private_runtime, materialize, parse_task_marker,
    task_marker_fingerprint, validate_live_authority, verify_files,
)
GUARD=ROOT/"scripts/release_identity_guard.py"
CI=ROOT/".github/workflows/ci.yml"
PROMOTION=ROOT/".github/workflows/promote-dependency-pr.yml"
RESERVATION_ID="11111111-1111-4111-8111-111111111111"
REPO_NAME_KEY="full"+"_name"

def acceptance_body(task_marker=""):
    body="""### Contexto

Fixture de promoción.

### Alcance

Validar autoridad.

### Fuera de alcance

Producción.

### Criterios de aceptación

- [ ] [AC-01] La reserva debe ser canónica.

### Contrato ejecutable

<!-- factory-acceptance {"version":1,"criteria":[{"id":"AC-01","kind":"test","target":"tests/test_release_identity_guard.py::ReleaseIdentityGuardTests::test_promotion_rejects_noncanonical_reservation_markers"}]} -->
"""
    return body + ("\n" + task_marker if task_marker else "")

ISSUE_338_ACCEPTANCE_PIN="7102a58c680bb5a6fd90d5e024e4138d9ad527b5c3cff18e08465199a39e5475"
ISSUE_338_CONTRACT_BODY="### Contexto\n\nFixture del contrato real #338.\n\n### Alcance\n\nVerificar fingerprint canónico.\n\n### Fuera de alcance\n\nNo ejecuta promoción.\n\n### Criterios de aceptación\n\n- [ ] [AC-01] El marker operativo exige schema/tipos Factory cerrados, `type(version) is int`, versión v2/v3 y `acceptance_sha256` igual al `contract_fingerprint()` canónico del Issue vivo; v3 exige además `task_marker_sha256` igual al fingerprint canónico del task vivo. v1, pins stale, extras o tipos inválidos fallan cerrado.\n- [ ] [AC-02] Una reserva v2/v3 solo autoriza mientras el Issue vivo sigue abierto y reservado y conserva latest-global + active + branch + owner + fingerprints vigentes; el caso sano v2/v3 permanece compatible.\n- [ ] [AC-03] El runtime de promoción no usa rutas públicas `/tmp`; su directorio workspace es privado (0700), fijo, vacío al iniciar y no-symlink.\n- [ ] [AC-04] El plan fija `planned_main_sha`, `planned_source_sha` y `planned_base_sha`; `materialize()` usa exactamente esas identidades y rechaza SHAs/títulos inválidos antes de escribir archivos.\n- [ ] [AC-05] Inmediatamente antes del push y antes de cerrar/crear PR se refrescan Issue + comentarios + `origin/main` + PR fuente y se revalidan autoridad/pins/SHAs/state/repo/base/autor. Cualquier drift antes del push aborta sin write; drift posterior al push provoca rollback de la rama sin cerrar el PR fuente.\n\n### Contrato ejecutable\n\n<!-- factory-acceptance {\"version\":1,\"criteria\":[{\"id\":\"AC-01\",\"kind\":\"test\",\"target\":\"tests/test_release_identity_guard.py::ReleaseIdentityGuardTests::test_promotion_rejects_noncanonical_reservation_markers\"},{\"id\":\"AC-02\",\"kind\":\"test\",\"target\":\"tests/test_release_identity_guard.py::ReleaseIdentityGuardTests::test_promotion_accepts_canonical_reservation_versions\"},{\"id\":\"AC-03\",\"kind\":\"test\",\"target\":\"tests/test_release_identity_guard.py::ReleaseIdentityGuardTests::test_promotion_runtime_is_private_workspace\"},{\"id\":\"AC-04\",\"kind\":\"test\",\"target\":\"tests/test_release_identity_guard.py::ReleaseIdentityGuardTests::test_materialize_rejects_untrusted_identity_fields\"},{\"id\":\"AC-05\",\"kind\":\"test\",\"target\":\"tests/test_release_identity_guard.py::ReleaseIdentityGuardTests::test_promotion_revalidates_reservation_before_remote_writes\"}]} -->\n"

def git(repo,*args):
    return subprocess.run(["git",*args],cwd=repo,text=True,capture_output=True,check=True).stdout.strip()

class ReleaseIdentityGuardTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup); self.root=Path(self.tmp.name)
    def repo(self,latest,candidate):
        r=self.root/(latest+"-"+candidate).replace(".","_"); r.mkdir(); (r/"config").mkdir()
        git(r,"init","-b","main"); git(r,"config","user.email","ci@condor.invalid"); git(r,"config","user.name","Condor CI")
        self.write(r,latest); git(r,"add","."); git(r,"commit","-m","base"); git(r,"tag","-a",f"v{latest}","-m","release")
        self.write(r,candidate); (r/"change").write_text("x",encoding="utf-8"); git(r,"add","."); git(r,"commit","-m","candidate")
        return r,git(r,"rev-parse","HEAD")
    def write(self,r,version):
        (r/"config/version.php").write_text(f"<?php\nreturn ['version' => '{version}'];\n",encoding="utf-8")
    def run_guard(self,r,title,sha):
        return subprocess.run([sys.executable,str(GUARD),"--repo-root",str(r),"--pr-title",title,"--candidate-sha",sha],text=True,capture_output=True)
    def promotion_fixture(self):
        pr={
            "number":309,
            "state":"open",
            "title":"chore(deps): bump the composer-minor group with 3 updates",
            "user":{"login":"dependabot[bot]"},
            "base":{"ref":"main","sha":"b"*40,"repo":{REPO_NAME_KEY:"pl0n3r/Condor"}},
            "head":{"sha":"a"*40,"repo":{REPO_NAME_KEY:"pl0n3r/Condor"}},
        }
        files=[{"filename":"composer.lock"}]
        issue={
            "number":336,
            "state":"open",
            "labels":[{"name":"estado: reservado"}],
            "body":acceptance_body(),
        }
        marker={
            "active":True,"branch":"trabajo/issue-336","owner":"pl0n3r",
            "reservation_id":RESERVATION_ID,"version":2,"reason":"tomar",
            "acceptance_sha256":contract_fingerprint(issue["body"]),
        }
        comments=[{"user":{"login":"github-actions[bot]"},"body":"<!-- condor-reserva "+json.dumps(marker,separators=(",",":"))+" -->"}]
        return pr,files,issue,comments
    def promotion_plan(self):
        pr,files,issue,comments=self.promotion_fixture()
        return build_plan(
            pr,files,issue,comments,RESERVATION_ID,
            "0.1.78","v0.1.78","pl0n3r/Condor","pl0n3r","c"*40,
        )

    def test_unused_monotonic_candidate_is_accepted(self):
        r,sha=self.repo("0.1.77","0.1.78"); p=self.run_guard(r,"ci: guard (V 0.1.78)",sha)
        self.assertEqual(p.returncode,0,p.stdout+p.stderr); self.assertIn("latest=v0.1.77",p.stdout)
    def test_existing_tag_on_other_commit_fails_closed_without_mutation(self):
        r,sha=self.repo("0.1.77","0.1.77"); before=git(r,"show-ref","--tags"); p=self.run_guard(r,"ci: reuse (V 0.1.77)",sha)
        self.assertNotEqual(p.returncode,0); self.assertIn("different SHA",p.stdout); self.assertEqual(before,git(r,"show-ref","--tags"))
    def test_non_monotonic_version_is_rejected_but_forward_gap_is_allowed(self):
        r,sha=self.repo("0.1.77","0.1.76"); self.assertNotEqual(self.run_guard(r,"ci: lower (V 0.1.76)",sha).returncode,0)
        r,sha=self.repo("0.1.77","0.1.80"); self.assertEqual(self.run_guard(r,"ci: gap (V 0.1.80)",sha).returncode,0)
    def test_pr_title_version_must_match_canonical_version(self):
        r,sha=self.repo("0.1.77","0.1.78"); p=self.run_guard(r,"ci: mismatch (V 0.1.79)",sha)
        self.assertNotEqual(p.returncode,0); self.assertIn("does not match",p.stdout)
    def test_ci_preflight_invokes_read_only_guard_before_expensive_jobs(self):
        w=CI.read_text(encoding="utf-8"); pre=w.split("\n  preflight:\n",1)[1].split("\n  documentacion:\n",1)[0]
        self.assertIn("permissions:\n  contents: read",w); self.assertNotIn("contents: write",pre); self.assertIn("fetch-depth: 0",pre)
        self.assertLess(pre.index("Validar identidad candidata de release"),pre.index("Clasificar cambios"))
        self.assertIn("github.event.pull_request.head.sha",pre)
        command='python3 scripts/release_identity_guard.py --pr-title "$TITULO_PR" --candidate-sha "$CANDIDATE_SHA"'
        self.assertIn(command,pre)
        self.assertNotIn("release_identity_guard.py \\\\",pre)
        self.assertIn("run: python3 tests/test_release_identity_guard.py",w)
        for job,name in (
            ("static-analysis","Análisis estático PHP"),
            ("rector-dry-run","Rector dry-run"),
            ("audit-npm","Auditoría de dependencias npm"),
            ("audit-composer","Auditoría de dependencias Composer"),
        ):
            with self.subTest(job=job):
                self.assertIn(f"  {job}:\n    name: {name}\n    needs: preflight",w)
    def test_historical_reuse_incidents_are_rejected_by_guard(self):
        for version,issue in [("0.1.40","#233"),("0.1.65","#307"),("0.1.66","#315"),("0.1.72","#330")]:
            with self.subTest(issue=issue):
                r,sha=self.repo(version,version); p=self.run_guard(r,f"incident {issue} (V {version})",sha)
                self.assertNotEqual(p.returncode,0); self.assertIn("different SHA",p.stdout)

    def test_bot_pr_cannot_merge_without_release_identity(self):
        r,sha=self.repo("0.1.78","0.1.78")
        p=self.run_guard(r,"chore(deps): bump the composer-minor group with 3 updates",sha)
        self.assertNotEqual(p.returncode,0)
        self.assertIn("PR title must end",p.stdout)

    def test_bot_pr_can_be_promoted_to_versioned_release(self):
        plan=self.promotion_plan()
        self.assertEqual(plan["version"],"0.1.79")
        self.assertEqual(plan["target_branch"],"trabajo/issue-336")
        self.assertEqual(plan["pr_title"],"chore(deps): promote bot PR #309 (V 0.1.79)")
        workflow=PROMOTION.read_text(encoding="utf-8")
        self.assertIn("workflow_dispatch:",workflow)
        self.assertIn("reservation_id:",workflow)
        self.assertIn("ref: trabajo/issue-",workflow)
        self.assertIn("inputs.issue_number",workflow)
        self.assertIn("<!-- condor-reserva-id: $RESERVATION_ID -->",workflow)
        self.assertEqual(workflow.count("EXPECTED_OWNER: ${{ github.actor }}"),3)
        self.assertIn('"$PROMOTION_RUNTIME/plan.json"',workflow)
        self.assertIn("--planned-main-sha",workflow)
        close_cmd='gh pr close "$SOURCE_PR"'
        create_cmd="gh pr create"
        self.assertIn(close_cmd,workflow)
        self.assertIn('gh pr reopen "$SOURCE_PR"',workflow)
        self.assertIn("--force-with-lease=",workflow)
        self.assertLess(workflow.index(close_cmd),workflow.index(create_cmd))
        create_block=workflow.split('pr_url="$(gh pr create',1)[1].split(')"',1)[0]
        for label in (
            "tipo: mejora","prioridad: media","estado: en revisión",
            "rol: ingenieria-software","rol: infraestructura","rol: qa","rol: sre",
        ):
            with self.subTest(label=label):
                self.assertIn(f'--label "{label}"',create_block)
        self.assertNotIn('gh pr edit "$pr_url"',workflow)
        self.assertGreaterEqual(workflow.count("validate-live"),2)
        push_cmd='git push origin "HEAD:$TARGET_BRANCH"'
        self.assertLess(workflow.index("validate-live", workflow.index("Commit y push")),workflow.index(push_cmd))
        self.assertLess(workflow.rindex("validate-live"),workflow.index(close_cmd))
        for unsafe_arg in (
            "--repo-root","--version-file","--files-json","--actual-files",
            "--github-output","--pr-json","--issue-json","--comments-json",
            "--source-pr","--source-title","--source-sha","--main-sha",
        ):
            with self.subTest(unsafe_arg=unsafe_arg):
                self.assertNotIn(unsafe_arg,workflow)
        root=self.root/"materialize"; (root/"config").mkdir(parents=True)
        self.write(root,"0.1.78")
        old_cwd=Path.cwd()
        try:
            import os
            os.chdir(root)
            materialize("0.1.79",336,309,plan["source_title"],plan["source_sha"],"c"*40)
        finally:
            os.chdir(old_cwd)
        self.assertIn("'version' => '0.1.79'",(root/"config/version.php").read_text(encoding="utf-8"))
        readme=(root/"README.md").read_text(encoding="utf-8")
        self.assertIn("Issue #336",readme); self.assertIn("PR automático #309",readme)

    def test_promotion_preserves_bot_dependency_diff(self):
        _,files,_,_=self.promotion_fixture()
        verify_files(files,["composer.lock"])
        with self.assertRaises(PromotionError):
            verify_files(files,["composer.json"])
        workflow=PROMOTION.read_text(encoding="utf-8")
        self.assertIn('git diff --binary "$BASE_SHA" "$SOURCE_SHA"',workflow)
        self.assertIn('git apply --3way --index "$PROMOTION_RUNTIME/source.patch"',workflow)
        self.assertIn("verify-files",workflow)
        self.assertNotIn("@dependabot rebase",workflow)

    def test_promoted_release_still_rejects_reused_version(self):
        r,sha=self.repo("0.1.78","0.1.78")
        p=self.run_guard(r,"chore(deps): promote bot PR #309 (V 0.1.78)",sha)
        self.assertNotEqual(p.returncode,0)
        self.assertIn("already exists",p.stdout)

    def test_dependabot_309_pattern_is_covered(self):
        plan=self.promotion_plan()
        self.assertEqual(plan["source_pr"],309)
        self.assertEqual(plan["source_title"],"chore(deps): bump the composer-minor group with 3 updates")
        self.assertEqual(plan["source_files"],["composer.lock"])
        self.assertEqual(plan["source_sha"],"a"*40)
        pr,files,issue,comments=self.promotion_fixture()
        pr["user"]["login"]="github-actions[bot]"
        with self.assertRaises(PromotionError):
            build_plan(pr,files,issue,comments,RESERVATION_ID,"0.1.78","v0.1.78","pl0n3r/Condor","pl0n3r","c"*40)
        pr,files,issue,comments=self.promotion_fixture()
        comments[0]["user"]["login"]="pl0n3r"
        with self.assertRaises(PromotionError):
            build_plan(pr,files,issue,comments,RESERVATION_ID,"0.1.78","v0.1.78","pl0n3r/Condor","pl0n3r","c"*40)
        pr,files,issue,comments=self.promotion_fixture()
        comments[0]["body"]=comments[0]["body"].replace('"owner":"pl0n3r"','"owner":"other-agent"')
        with self.assertRaises(PromotionError):
            build_plan(pr,files,issue,comments,RESERVATION_ID,"0.1.78","v0.1.78","pl0n3r/Condor","pl0n3r","c"*40)

        pr,files,issue,comments=self.promotion_fixture()
        newer_id="22222222-2222-4222-8222-222222222222"
        newer={
            "active":True,
            "branch":"trabajo/issue-336",
            "owner":"pl0n3r",
            "reservation_id":newer_id,
            "version":2,
            "reason":"tomar",
            "acceptance_sha256":contract_fingerprint(issue["body"]),
        }
        comments.append({
            "user":{"login":"github-actions[bot]"},
            "body":"<!-- condor-reserva "+json.dumps(newer,separators=(",",":"))+" -->",
        })
        with self.assertRaises(PromotionError):
            build_plan(pr,files,issue,comments,RESERVATION_ID,"0.1.78","v0.1.78","pl0n3r/Condor","pl0n3r","c"*40)
        latest=build_plan(pr,files,issue,comments,newer_id,"0.1.78","v0.1.78","pl0n3r/Condor","pl0n3r","c"*40)
        self.assertEqual(latest["reservation_id"],newer_id)
        comments[-1]["body"]=comments[-1]["body"].replace('"active":true','"active":false')
        with self.assertRaises(PromotionError):
            build_plan(pr,files,issue,comments,newer_id,"0.1.78","v0.1.78","pl0n3r/Condor","pl0n3r","c"*40)
        workflow=PROMOTION.read_text(encoding="utf-8")
        self.assertIn('gh api --paginate "repos/$GITHUB_REPOSITORY/issues/$ISSUE_NUMBER/comments?per_page=100"',workflow)
        pr,files,issue,comments=self.promotion_fixture()
        comments=[{"user":{"login":"someone"},"body":"noise-comment"} for _ in range(100)]+comments
        newer_id="33333333-3333-4333-8333-333333333333"
        newer={
            "active":True,"branch":"trabajo/issue-336","owner":"pl0n3r",
            "reservation_id":newer_id,"version":2,"reason":"tomar",
            "acceptance_sha256":contract_fingerprint(issue["body"]),
        }
        comments.append({"user":{"login":"github-actions[bot]"},"body":"<!-- condor-reserva "+json.dumps(newer,separators=(",",":"))+" -->"})
        with self.assertRaises(PromotionError):
            build_plan(pr,files,issue,comments,RESERVATION_ID,"0.1.78","v0.1.78","pl0n3r/Condor","pl0n3r","c"*40)
        latest=build_plan(pr,files,issue,comments,newer_id,"0.1.78","v0.1.78","pl0n3r/Condor","pl0n3r","c"*40)
        self.assertEqual(latest["reservation_id"],newer_id)

    def test_promotion_rejects_noncanonical_reservation_markers(self):
        pr,files,issue,_=self.promotion_fixture()
        base={
            "active":True,
            "branch":"trabajo/issue-336",
            "owner":"pl0n3r",
            "reservation_id":RESERVATION_ID,
            "version":2,
            "reason":"tomar",
            "acceptance_sha256":"f"*64,
        }
        invalid=[]
        missing=dict(base); missing.pop("acceptance_sha256"); invalid.append(missing)
        extra=dict(base); extra["unexpected"]="x"; invalid.append(extra)
        wrong_type=dict(base); wrong_type["active"]="true"; invalid.append(wrong_type)
        incomplete_v3=dict(base); incomplete_v3["version"]=3; invalid.append(incomplete_v3)
        for version in (True,1.0,2.0,3.0):
            ambiguous=dict(base); ambiguous["version"]=version; invalid.append(ambiguous)
        for payload in invalid:
            comments=[{
                "user":{"login":"github-actions[bot]"},
                "body":"<!-- condor-reserva "+json.dumps(payload,separators=(",",":"))+" -->",
            }]
            with self.subTest(payload=payload):
                with self.assertRaises(PromotionError):
                    build_plan(
                        pr,files,issue,comments,RESERVATION_ID,
                        "0.1.78","v0.1.78","pl0n3r/Condor","pl0n3r","c"*40,
                    )

        legacy={
            "active":True,
            "branch":"trabajo/issue-336",
            "owner":"pl0n3r",
            "reservation_id":RESERVATION_ID,
            "version":1,
            "reason":"tomar",
        }
        legacy_comments=[{
            "user":{"login":"github-actions[bot]"},
            "body":"<!-- condor-reserva "+json.dumps(legacy,separators=(",",":"))+" -->",
        }]
        with self.assertRaises(PromotionError):
            build_plan(
                pr,files,issue,legacy_comments,RESERVATION_ID,
                "0.1.78","v0.1.78","pl0n3r/Condor","pl0n3r","c"*40,
            )

    def test_promotion_accepts_canonical_reservation_versions(self):
        self.assertEqual(
            contract_fingerprint(ISSUE_338_CONTRACT_BODY),
            ISSUE_338_ACCEPTANCE_PIN,
        )
        pr,files,issue,comments=self.promotion_fixture()
        self.assertEqual(
            build_plan(
                pr,files,issue,comments,RESERVATION_ID,
                "0.1.78","v0.1.78","pl0n3r/Condor","pl0n3r","c"*40,
            )["reservation_id"],
            RESERVATION_ID,
        )
        task_payload={
            "version":1,
            "epic":338,
            "task_key":"PROMOTION",
            "order":1,
            "owner":"pl0n3r",
            "roles":["seguridad"],
            "depends_on":[335],
            "paths":["scripts/dependency_pr_promotion.py"],
        }
        task_marker="<!-- factory-plan-task "+json.dumps(task_payload,separators=(",",":"),sort_keys=True)+" -->"
        issue["body"]=acceptance_body(task_marker)
        v3={
            "active":True,
            "branch":"trabajo/issue-336",
            "owner":"pl0n3r",
            "reservation_id":RESERVATION_ID,
            "version":3,
            "reason":"tomar",
            "acceptance_sha256":contract_fingerprint(issue["body"]),
            "task_marker_sha256":task_marker_fingerprint(parse_task_marker(issue["body"])),
            "task_paths":["scripts/dependency_pr_promotion.py"],
            "task_depends_on":[335],
        }
        comments=[{
            "user":{"login":"github-actions[bot]"},
            "body":"<!-- condor-reserva "+json.dumps(v3,separators=(",",":"))+" -->",
        }]
        self.assertEqual(
            build_plan(
                pr,files,issue,comments,RESERVATION_ID,
                "0.1.78","v0.1.78","pl0n3r/Condor","pl0n3r","c"*40,
            )["reservation_id"],
            RESERVATION_ID,
        )

    def test_promotion_runtime_is_private_workspace(self):
        workflow=PROMOTION.read_text(encoding="utf-8")
        self.assertNotIn("/tmp/",workflow)
        self.assertIn(".condor-runtime/dependency-promotion",workflow)
        self.assertFalse(str(RUNTIME_DIR).startswith("/tmp/"))
        runtime=ensure_private_runtime(self.root/"runtime")
        self.assertTrue(runtime.is_dir())
        self.assertFalse(runtime.is_symlink())
        self.assertEqual(runtime.stat().st_mode & 0o077,0)
        (runtime/"residue").write_text("stale",encoding="utf-8")
        with self.assertRaises(PromotionError):
            ensure_private_runtime(runtime)
        target=self.root/"target"; target.mkdir()
        link=self.root/"link"; link.symlink_to(target,target_is_directory=True)
        with self.assertRaises(PromotionError):
            ensure_private_runtime(link/"child")

    def test_materialize_rejects_untrusted_identity_fields(self):
        root=self.root/"materialize-invalid"; (root/"config").mkdir(parents=True)
        self.write(root,"0.1.79")
        old_cwd=Path.cwd()
        try:
            import os
            os.chdir(root)
            with self.assertRaises(PromotionError):
                materialize("0.1.80",338,309,"valid title","bad-sha","c"*40)
            with self.assertRaises(PromotionError):
                materialize("0.1.80",338,309,"valid title","a"*40,"bad-sha")
            with self.assertRaises(PromotionError):
                materialize("0.1.80",338,309,"bad\ntitle","a"*40,"c"*40)
        finally:
            os.chdir(old_cwd)
        self.assertIn(
            "'version' => '0.1.79'",
            (root/"config/version.php").read_text(encoding="utf-8"),
        )
        self.assertFalse((root/"README.md").exists())

    def test_promotion_revalidates_reservation_before_remote_writes(self):
        plan=self.promotion_plan()
        pr,_,issue,comments=self.promotion_fixture()
        validate_live_authority(
            plan,issue,comments,pr,"c"*40,"pl0n3r","pl0n3r/Condor",
        )

        mutations=[]
        closed=json.loads(json.dumps(issue)); closed["state"]="closed"; mutations.append((closed,comments,pr,"c"*40))
        unreserved=json.loads(json.dumps(issue)); unreserved["labels"]=[]; mutations.append((unreserved,comments,pr,"c"*40))
        stale_comments=json.loads(json.dumps(comments))
        stale_payload=json.loads(stale_comments[-1]["body"].split("condor-reserva ",1)[1].split(" -->",1)[0])
        stale_payload["acceptance_sha256"]="0"*64
        stale_comments[-1]["body"]="<!-- condor-reserva "+json.dumps(stale_payload,separators=(",",":"))+" -->"
        mutations.append((issue,stale_comments,pr,"c"*40))
        head_drift=json.loads(json.dumps(pr)); head_drift["head"]["sha"]="d"*40; mutations.append((issue,comments,head_drift,"c"*40))
        base_drift=json.loads(json.dumps(pr)); base_drift["base"]["sha"]="e"*40; mutations.append((issue,comments,base_drift,"c"*40))
        source_closed=json.loads(json.dumps(pr)); source_closed["state"]="closed"; mutations.append((issue,comments,source_closed,"c"*40))
        mutations.append((issue,comments,pr,"f"*40))
        for current_issue,current_comments,current_pr,current_main in mutations:
            with self.subTest(issue=current_issue.get("state"),main=current_main,source=current_pr.get("state")):
                with self.assertRaises(PromotionError):
                    validate_live_authority(
                        plan,current_issue,current_comments,current_pr,current_main,
                        "pl0n3r","pl0n3r/Condor",
                    )

        workflow=PROMOTION.read_text(encoding="utf-8")
        push='git push origin "HEAD:$TARGET_BRANCH"'
        close='gh pr close "$SOURCE_PR"'
        self.assertGreaterEqual(workflow.count("validate-live"),2)
        first_live=workflow.index("validate-live",workflow.index("Commit y push"))
        self.assertLess(first_live,workflow.index(push))
        self.assertLess(workflow.rindex("validate-live"),workflow.index(close))
        self.assertGreaterEqual(workflow.count('pulls/$SOURCE_PR" > "$PROMOTION_RUNTIME/source-pr.json"'),3)
        self.assertGreaterEqual(workflow.count('issues/$ISSUE_NUMBER" > "$PROMOTION_RUNTIME/promotion-issue.json"'),3)
        self.assertGreaterEqual(workflow.count('$PROMOTION_RUNTIME/promotion-comments.json'),3)
        self.assertIn('planned_main_sha="$(jq -r',workflow)
        self.assertIn('$planned_main_sha:refs/heads/$TARGET_BRANCH',workflow)
        self.assertNotIn('\n          main_sha="$(git rev-parse origin/main)"',workflow)
        self.assertNotIn("/tmp/",workflow)

if __name__=="__main__":
    unittest.main()
