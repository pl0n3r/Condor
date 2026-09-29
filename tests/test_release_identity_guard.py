import subprocess,sys,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
GUARD=ROOT/"scripts/release_identity_guard.py"
CI=ROOT/".github/workflows/ci.yml"

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
    def test_historical_reuse_incidents_are_rejected_by_guard(self):
        for version,issue in [("0.1.40","#233"),("0.1.65","#307"),("0.1.66","#315"),("0.1.72","#330")]:
            with self.subTest(issue=issue):
                r,sha=self.repo(version,version); p=self.run_guard(r,f"incident {issue} (V {version})",sha)
                self.assertNotEqual(p.returncode,0); self.assertIn("different SHA",p.stdout)
if __name__=="__main__": unittest.main()
