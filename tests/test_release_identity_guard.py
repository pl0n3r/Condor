import json,subprocess,sys,tempfile,unittest
from pathlib import Path

from scripts.dependency_pr_promotion import (
    PromotionError, build_plan, materialize, verify_files,
)

ROOT=Path(__file__).resolve().parents[1]
GUARD=ROOT/"scripts/release_identity_guard.py"
CI=ROOT/".github/workflows/ci.yml"
PROMOTION=ROOT/".github/workflows/promote-dependency-pr.yml"
RESERVATION_ID="11111111-1111-4111-8111-111111111111"

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
            "base":{"ref":"main","sha":"b"*40,"repo":{"full_name":"pl0n3r/Condor"}},
            "head":{"sha":"a"*40,"repo":{"full_name":"pl0n3r/Condor"}},
        }
        files=[{"filename":"composer.lock"}]
        issue={"number":336,"state":"open","labels":[{"name":"estado: reservado"}]}
        marker={"active":True,"branch":"trabajo/issue-336","owner":"pl0n3r","reservation_id":RESERVATION_ID,"version":2}
        comments=[{"body":"<!-- condor-reserva "+json.dumps(marker,separators=(",",":"))+" -->"}]
        return pr,files,issue,comments
    def promotion_plan(self):
        pr,files,issue,comments=self.promotion_fixture()
        return build_plan(pr,files,issue,comments,RESERVATION_ID,"0.1.78","v0.1.78","pl0n3r/Condor")

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
        root=self.root/"materialize"; (root/"config").mkdir(parents=True)
        self.write(root,"0.1.78")
        materialize(root,"0.1.79",336,309,plan["source_title"],plan["source_sha"],"c"*40)
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
        self.assertIn("git apply --3way --index /tmp/source.patch",workflow)
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
            build_plan(pr,files,issue,comments,RESERVATION_ID,"0.1.78","v0.1.78","pl0n3r/Condor")

if __name__=="__main__":
    unittest.main()
