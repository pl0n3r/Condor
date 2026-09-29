import json
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
SECTIONS=(
    "Operational Cockpit","Work Queue","Qué hace el producto",
    "Arquitectura en 60 segundos","Stack e infraestructura","Ciclo de entrega",
    "Calidad y seguridad","Roadmap y fuentes de verdad","Desarrollo local",
    "Mapa de la fábrica",
)
STATUS=("<!-- factory:status:start -->","<!-- factory:status:end -->")
PROGRESS=("<!-- factory:progress-readiness:start -->","<!-- factory:progress-readiness:end -->")

class ReadmeContractAdoptionTests(unittest.TestCase):
    def read(self,path): return (ROOT/path).read_text(encoding="utf-8")

    def test_project_metadata_is_stable_and_complete(self):
        data=json.loads(self.read("readme/project.json"))
        self.assertEqual({"name","tagline","role","phase","roadmap","stack"},set(data))
        self.assertEqual("Condor App",data["name"]); self.assertEqual("construction",data["phase"])
        self.assertEqual("https://github.com/pl0n3r/Condor/issues/1",data["roadmap"])

    def test_readme_has_contract_v1_anatomy(self):
        text=self.read("README.md"); pos=[]
        self.assertEqual("# Condor App",text.splitlines()[0])
        for section in SECTIONS:
            heading=f"## {section}"; self.assertEqual(1,text.count(heading),heading); pos.append(text.index(heading))
        self.assertEqual(sorted(pos),pos)

    def test_derived_blocks_fail_closed_without_evidence(self):
        text=self.read("README.md")
        for marker in (*STATUS,*PROGRESS): self.assertEqual(1,text.count(marker),marker)
        status=text.split(STATUS[0],1)[1].split(STATUS[1],1)[0]
        progress=text.split(PROGRESS[0],1)[1].split(PROGRESS[1],1)[0]
        self.assertNotIn("GREEN",status); self.assertNotIn("DEGRADED",status)
        self.assertIn("| main SHA | UNKNOWN |",status)
        self.assertIn("| Readiness | UNKNOWN |",progress)

    def test_consumer_workflow_uses_factory_v1(self):
        wf=self.read(".github/workflows/readme-contract.yml")
        self.assertIn("uses: pl0n3r/factory/.github/workflows/readme.yml@v1",wf)
        self.assertIn("readme_path: README.md",wf); self.assertIn("metadata_path: readme/project.json",wf)
        self.assertNotIn("readme.yml@main",wf)

    def test_work_queue_links_canonical_roadmap(self):
        text=self.read("README.md")
        q=text.split("## Work Queue",1)[1].split("## Qué hace el producto",1)[0]
        for label in ("NOW","NEXT","LATER","BLOCKED"): self.assertEqual(1,q.count(f"**{label}:**"))
        self.assertIn("https://github.com/pl0n3r/Condor/issues/1",q)

    def test_factory_map_preserves_roles(self):
        text=self.read("README.md").split("## Mapa de la fábrica",1)[1]
        for phrase in ("**Factory:** governance/kit","**ControlBot:** control plane","**FactoryRunner:** execution plane","**AutoFactory:** herramienta local/manual","**Condor / GrindFlow / BRVTAL:** productos"):
            self.assertIn(phrase,text)

    def test_dependency_promotion_preserves_readme_contract(self):
        script=self.read("scripts/dependency_pr_promotion.py")
        wf=self.read(".github/workflows/promote-dependency-pr.yml")
        self.assertNotIn('README_FILE = Path("README.md")',script)
        self.assertNotIn("README_FILE.write_text",script)
        self.assertIn("git add config/version.php",wf)
        self.assertNotIn("git add config/version.php README.md",wf)
        self.assertIn("config/version.php",self.read("README.md"))
        self.assertNotIn("Snapshot operativo",self.read("README.md"))

if __name__=="__main__": unittest.main()
