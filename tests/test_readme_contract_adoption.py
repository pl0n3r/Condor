import json
import re
import unittest
from pathlib import Path
from urllib.parse import parse_qs, urlsplit

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

    def test_readme_has_contract_v2_anatomy(self):
        text=self.read("README.md"); pos=[]
        self.assertEqual("# Condor App",text.splitlines()[0])
        for section in SECTIONS:
            heading=f"## {section}"; self.assertEqual(1,text.count(heading),heading); pos.append(text.index(heading))
        self.assertEqual(sorted(pos),pos)

    def test_readme_is_contract_v2_without_unknown_blocks(self):
        text=self.read("README.md")
        cockpit=text.split("## Operational Cockpit",1)[1].split("\n## ",1)[0]
        for stale in (*STATUS,*PROGRESS,"UNKNOWN","Progress + Readiness"):
            self.assertNotIn(stale,text)
        self.assertNotIn("| Señal | Estado |",cockpit)
        self.assertIn("https://github.com/pl0n3r/Condor/actions/workflows/ci.yml",cockpit)
        self.assertIn("badge.svg?branch=main",cockpit)
        self.assertIn("https://github.com/pl0n3r/Condor/releases",cockpit)
        self.assertIn("img.shields.io/github/v/release/pl0n3r/Condor",cockpit)
        self.assertIn("https://control.condorapp.com.co/",cockpit)
        positions=[text.index(f"## {section}") for section in SECTIONS]
        self.assertEqual(sorted(positions),positions)

    def test_product_areas_match_eight_operational_modules(self):
        text=self.read("README.md")
        product=text.split("## Qué hace el producto",1)[1].split("## Arquitectura en 60 segundos",1)[0]
        entries=[line for line in product.splitlines() if line.startswith("- **")]
        expected=(
            "Catálogo, variantes y precios", "Tienda y checkout",
            "Pedidos y ventas", "Clientes y categorías",
            "Inventario por sede", "Compras y proveedores",
            "Producción y materiales", "Reportes CSV tenant-safe",
        )
        self.assertEqual(8,len(entries))
        for actual,area in zip(entries,expected):
            self.assertTrue(actual.startswith(f"- **{area}:**"),actual)
        self.assertIn("OrderController.php",entries[2])
        self.assertIn("CustomerController.php",entries[3])

    def test_readme_caller_only_on_document_changes(self):
        """F2B: preserve exact reusable README validation on documentation edits."""
        workflow=self.read(".github/workflows/readme-contract.yml")
        expected=["README.md","readme/**",".github/workflows/readme-contract.yml"]

        def paths(source,event,next_event):
            section=source.split(f"  {event}:\n",1)[1].split(f"\n  {next_event}:\n",1)[0]
            return [line.strip()[2:].strip("'\\\"")
                    for line in section.splitlines() if line.strip().startswith("- ")]

        self.assertIn("  workflow_dispatch:\n",workflow)
        self.assertIn("    branches: [main]\n",workflow)
        self.assertIn("    types: [opened, synchronize, reopened, ready_for_review]",workflow)
        self.assertEqual(2,workflow.count("    paths:\n"))
        for event,next_event in (("pull_request","push"),("push","workflow_dispatch")):
            with self.subTest(event=event):
                self.assertEqual(expected,paths(workflow,event,next_event))
                self.assertEqual(1,workflow.split(f"  {event}:\n",1)[1].split(f"\n  {next_event}:\n",1)[0].count("    paths:\n"))

        # Test negative mutations for BOTH event scopes independently:
        # a narrow pull_request filter cannot mask an unsafe push filter.
        for event,next_event in (("pull_request","push"),("push","workflow_dispatch")):
            original=workflow.split(f"  {event}:\n",1)[1].split(f"\n  {next_event}:\n",1)[0]
            variants=[
                original.replace("- 'readme/**'","- '**'",1),
                original.replace("      - 'README.md'\n","",1),
                original.replace("- '.github/workflows/readme-contract.yml'","- 'src/**'",1),
            ]
            for case,changed in enumerate(variants):
                with self.subTest(event=event,case=case):
                    self.assertNotEqual(original,changed)
                    candidate=workflow.replace(
                        f"  {event}:\n"+original+f"\n  {next_event}:\n",
                        f"  {event}:\n"+changed+f"\n  {next_event}:\n",1)
                    self.assertNotEqual(expected,paths(candidate,event,next_event))

        self.assertIn("uses: pl0n3r/factory/.github/workflows/readme.yml@v1",workflow)
        self.assertIn("readme_path: README.md",workflow)
        self.assertIn("metadata_path: readme/project.json",workflow)
        self.assertIn("permissions:\n  contents: read",workflow)
        self.assertNotIn("contents: write",workflow)
        self.assertNotIn("readme.yml@main",workflow)
        self.assertIn("cancel-in-progress: true",workflow)
        self.assertIn("github.event.pull_request.draft == false",workflow)

    def assert_work_queue_now_is_dynamic(self, text):
        work_queue=text.split("## Work Queue",1)[1].split("## Qué hace el producto",1)[0]
        now=[line for line in work_queue.splitlines() if line.startswith("- **NOW:** ")]
        self.assertEqual(1,len(now))
        self.assertNotIn("#824",now[0])
        self.assertNotIn("/issues/824",now[0])
        links=re.findall(r"\]\((https://github\.com/pl0n3r/Condor/issues\?q=[^)]+)\)",now[0])
        self.assertEqual(2,now[0].count("]("))
        self.assertEqual(2,len(links))
        queries=[parse_qs(urlsplit(link).query)["q"][0] for link in links]
        self.assertEqual([
            'is:issue is:open label:"estado: reservado"',
            'is:issue is:open label:"estado: en revisión"',
        ],queries)

    def test_work_queue_now_excludes_closed_824(self):
        self.assert_work_queue_now_is_dynamic(self.read("README.md"))

    def test_work_queue_links_canonical_roadmap(self):
        text=self.read("README.md")
        q=text.split("## Work Queue",1)[1].split("## Qué hace el producto",1)[0]
        for label in ("NOW","NEXT","LATER","BLOCKED"): self.assertEqual(1,q.count(f"**{label}:**"))
        self.assertIn("https://github.com/pl0n3r/Condor/issues/1",q)
        self.assert_work_queue_now_is_dynamic(text)

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
