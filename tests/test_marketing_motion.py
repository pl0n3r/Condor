import hashlib
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEMPLATES = ROOT / "templates"
PUBLIC = ROOT / "public"
GSAP_DIR = PUBLIC / "lib" / "gsap"
GATE = PUBLIC / "marketing-motion-gate.js"
MOTION = PUBLIC / "marketing-motion.js"
CSS = PUBLIC / "marketing.css"
HEAD_PARTIAL = TEMPLATES / "marketing" / "_motion_head.html.twig"
SCRIPTS_PARTIAL = TEMPLATES / "marketing" / "_motion_scripts.html.twig"

# GSAP 3.15.0 tal cual del paquete npm; ver public/lib/gsap/README.md.
GSAP_SHA256 = {
    "gsap.min.js": "92bb9a96476f983d212a2bc4f54c889039c1696dd4461d40a736860938570fbb",
    "SplitText.min.js": "419f7027a5f086a12cb7988736d8fdd3a6ed2200229661de25b6628ca7ced344",
}
APPROVED_SCRIPTS = (
    "/lib/gsap/gsap.min.js",
    "/lib/gsap/SplitText.min.js",
    "/marketing-motion.js",
)


def text(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def public_pages() -> list[Path]:
    pages = [TEMPLATES / "home" / "index.html.twig"]
    pages += sorted(p for p in (TEMPLATES / "marketing").glob("*.html.twig") if not p.name.startswith("_"))
    return pages


def selectors(source: str, name: str) -> set[str]:
    match = re.search(rf"const {name} = '([^']+)';", source)
    assert match, f"No se encontró la constante {name} en marketing-motion.js"
    return {part.strip() for part in match.group(1).split(",")}


class MarketingMotionTests(unittest.TestCase):
    def test_gsap_is_local_pinned_and_without_scrolltrigger(self) -> None:
        for name, expected in GSAP_SHA256.items():
            with self.subTest(file=name):
                digest = hashlib.sha256((GSAP_DIR / name).read_bytes()).hexdigest()
                self.assertEqual(digest, expected)
                self.assertIn(expected, text(GSAP_DIR / "README.md"))
        self.assertFalse((GSAP_DIR / "ScrollTrigger.min.js").exists(), "ScrollTrigger usa style.cssText y la CSP style-src 'self' lo bloquea")

    def test_every_public_page_loads_only_the_approved_motion(self) -> None:
        pages = public_pages()
        self.assertGreaterEqual(len(pages), 5)
        for page in pages:
            source = text(page)
            with self.subTest(page=page.name):
                self.assertNotIn("<script", source.lower())
                self.assertIn("{% include 'marketing/_motion_head.html.twig' %}", source)
                self.assertIn("{% include 'marketing/_motion_scripts.html.twig' %}", source)
                self.assertLess(source.index("_motion_head.html.twig"), source.index("{% block body %}"))
        self.assertEqual(re.findall(r'<script src="([^"]+)"', text(HEAD_PARTIAL)), ["/marketing-motion-gate.js"])
        self.assertEqual(tuple(re.findall(r'<script src="([^"]+)" defer>', text(SCRIPTS_PARTIAL))), APPROVED_SCRIPTS)
        for partial in (HEAD_PARTIAL, SCRIPTS_PARTIAL):
            self.assertEqual(len(re.findall(r"<script", text(partial))), len(re.findall(r'<script src="/', text(partial))))
            self.assertNotIn("http", re.sub(r"\{#.*?#\}", "", text(partial)))

    def test_content_is_visible_by_default_and_hidden_only_under_the_motion_class(self) -> None:
        css = text(CSS)
        source = text(MOTION)
        hidden = selectors(source, "TITLE") | selectors(source, "HERO_FOLLOW") | selectors(source, "HEADS") | selectors(source, "ITEMS") | selectors(source, "STOPS")
        rule = re.search(r"((?:html\.mk-motion [^,{]+,\s*)+html\.mk-motion [^,{]+)\{\s*opacity: 0;\s*\}", css)
        self.assertIsNotNone(rule, "Falta la regla de estado oculto bajo html.mk-motion")
        in_css = {part.strip().removeprefix("html.mk-motion ") for part in rule.group(1).split(",")}
        self.assertEqual(in_css, hidden, "Los selectores del CSS y de marketing-motion.js deben coincidir")
        print_block = css[css.index("@media print"):]
        for selector in hidden:
            with self.subTest(selector=selector):
                self.assertIn(f"html.mk-motion.mk-motion {selector}", print_block)
        self.assertEqual(len(re.findall(r"html\.mk-motion[^{]*\{[^}]*opacity: 0;", css)), 1)

    def test_motion_has_a_failsafe_and_a_calm_path(self) -> None:
        gate, motion = text(GATE), text(MOTION)
        self.assertIn("(prefers-reduced-motion: reduce)", gate)
        self.assertIn("mk-motion-calm", gate)
        self.assertRegex(gate, r"setTimeout\([\s\S]*mk-motion-ready[\s\S]*classList\.remove\('mk-motion', 'mk-motion-calm'\)")
        self.assertIn("gsap.matchMedia()", motion)
        self.assertIn("'(prefers-reduced-motion: reduce)'", motion)
        self.assertIn("'(prefers-reduced-motion: no-preference)'", motion)
        self.assertIn("giveUp()", motion)
        catches = re.findall(r"catch \{\n\s*//[^\n]+\n\s*giveUp\(\);", motion)
        self.assertGreaterEqual(len(catches), 2, "Cada catch explica por qué se ignora la excepción y apaga el movimiento")
        self.assertIn("mk-motion-ready", motion)
        self.assertIn("IntersectionObserver", motion)

    def test_motion_respects_the_content_security_policy(self) -> None:
        # style-src 'self' bloquea style.cssText, setAttribute('style') y los estilos en línea; script-src 'self', eval.
        for path in (GATE, MOTION):
            code = re.sub(r"/\*.*?\*/", "", text(path), flags=re.DOTALL)
            with self.subTest(file=path.name):
                for forbidden in ("cssText", "setAttribute('style'", 'setAttribute("style"', "innerHTML", "eval(", "new Function", "fetch(", "XMLHttpRequest", "http://", "https://", "ScrollTrigger"):
                    self.assertNotIn(forbidden, code)


if __name__ == "__main__":
    unittest.main()
