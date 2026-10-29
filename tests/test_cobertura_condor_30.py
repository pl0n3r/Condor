#!/usr/bin/env python3
"""Condor #1349: comportamiento PHP real de identidad pública, idioma y tenancy.

La prueba no certifica independencia entre repositorios, política de roadmap,
hosting real, go-live ni paridad de toda la suite comercial.
No inspecciona el estado de ninguna matriz de cobertura.
"""
from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCES = (
    "src/Application/Storefront/StorefrontPresentation.php",
    "src/Application/Storefront/PublicCatalogSearch.php",
    "src/Domain/Identity/PermissionCatalog.php",
    "src/Shared/Version/AppVersion.php",
)

# Solo simular los límites ORM/entidades, nunca las decisiones de las clases
# reales: se requieren y ejecutan las implementaciones PHP del repositorio.
PROBE = r"""
namespace Doctrine\ORM {
    interface EntityManagerInterface {
        public function getRepository(string $class): object;
    }
}
namespace App\Domain\Organization\Entity {
    final class Tenant {
        public function __construct(private string $name, private string $slug) {}
        public function name(): string { return $this->name; }
        public function slug(): string { return $this->slug; }
    }
    final class StorefrontProfile {
        public function __construct(private Tenant $tenant, private string $headline) {}
        public function tenant(): Tenant { return $this->tenant; }
        public function headline(): string { return $this->headline; }
        public function description(): string { return 'Descripción sintética'; }
    }
    final class TenantDomain {
        public function __construct(
            private Tenant $tenant, private string $hostname,
            private bool $primary, private bool $verified
        ) {}
        public function tenant(): Tenant { return $this->tenant; }
        public function hostname(): string { return $this->hostname; }
        public function isPrimary(): bool { return $this->primary; }
        public function isVerified(): bool { return $this->verified; }
    }
}
namespace {
    require 'src/Application/Storefront/StorefrontPresentation.php';
    require 'src/Application/Storefront/PublicCatalogSearch.php';
    require 'src/Domain/Identity/PermissionCatalog.php';
    require 'src/Shared/Version/AppVersion.php';

    use App\Application\Storefront\StorefrontPresentation;
    use App\Application\Storefront\PublicCatalogSearch;
    use App\Domain\Identity\PermissionCatalog;
    use App\Domain\Organization\Entity\Tenant;
    use App\Domain\Organization\Entity\StorefrontProfile;
    use App\Domain\Organization\Entity\TenantDomain;
    use App\Shared\Version\AppVersion;
    use Doctrine\ORM\EntityManagerInterface;

    final class FixtureRepository {
        public function __construct(private array $rows) {}
        private function matching(array $criteria): array {
            return array_values(array_filter($this->rows, static function ($row) use ($criteria) {
                foreach ($criteria as $name => $value) {
                    $actual = match ($name) {
                        'tenant' => $row->tenant(),
                        'primary' => $row instanceof TenantDomain && $row->isPrimary(),
                        'verified' => $row instanceof TenantDomain && $row->isVerified(),
                        default => null,
                    };
                    if ($actual !== $value) return false;
                }
                return true;
            }));
        }
        public function findOneBy(array $criteria): mixed {
            return $this->matching($criteria)[0] ?? null;
        }
        public function findBy(array $criteria, array $orderBy = [], ?int $limit = null): array {
            $matches = $this->matching($criteria);
            return $limit === null ? $matches : array_slice($matches, 0, $limit);
        }
    }
    final class FixtureManager implements EntityManagerInterface {
        public function __construct(private array $profiles, private array $domains) {}
        public function getRepository(string $class): object {
            return new FixtureRepository(
                $class === StorefrontProfile::class ? $this->profiles :
                ($class === TenantDomain::class ? $this->domains : [])
            );
        }
    }
    function denied(callable $action): bool {
        try { $action(); } catch (\InvalidArgumentException|\DomainException) { return true; }
        return false;
    }

    $a = new Tenant('Empresa de prueba A', 'empresa-a');
    $b = new Tenant('Empresa de prueba B', 'empresa-b');
    $own = new TenantDomain($a, 'empresa-a.example.invalid', true, true);
    $foreign = new TenantDomain($b, 'empresa-b.example.invalid', true, true);
    $presenter = new StorefrontPresentation(new FixtureManager(
        [new StorefrontProfile($a, 'Portada A'), new StorefrontProfile($b, 'Portada B')],
        [$own, $foreign]
    ));
    $identity = $presenter->publicIdentity($a);
    $domains = $presenter->registeredDomains($a);
    $missing = (new StorefrontPresentation(new FixtureManager([], [$foreign])))->publicIdentity($a);
    $duplicate = (new StorefrontPresentation(new FixtureManager([], [
        $own, new TenantDomain($a, 'otra.example.invalid', true, true)
    ])))->publicIdentity($a);

    $filters = PublicCatalogSearch::fromQuery([
        'q'=>'  telas  ', 'categoria'=>'MAYORISTAS', 'pagina'=>'2'
    ]);
    $pageUrl = $filters->pageHref(3);
    $permissions = PermissionCatalog::normalize([
        'catalog.view', 'catalog.view', 'catalog.create'
    ]);
    $version = new AppVersion(getcwd());

    echo json_encode([
        'brand' => [
            'name'=>$identity['name'], 'slug'=>$identity['slug'],
            'headline'=>$identity['headline'], 'canonical'=>$identity['canonical'],
            'version'=>$version->human(),
            'domains'=>$domains,
        ],
        'locale' => [
            'term'=>$filters->term(), 'category'=>$filters->categorySlug(),
            'page'=>$filters->page(), 'url'=>$pageUrl,
            'bad_page'=>denied(fn()=>PublicCatalogSearch::fromQuery(['pagina'=>'0'])),
            'forged_type'=>denied(fn()=>PublicCatalogSearch::fromQuery(['q'=>[]])),
            'unknown_category'=>denied(fn()=>PublicCatalogSearch::fromQuery(['categoria'=>'<script>'])),
        ],
        'tenant' => [
            'fallback'=>$missing['canonical'], 'duplicate'=>$duplicate['canonical'],
            'foreign_hidden'=>count($domains) === 1 && $domains[0]['host'] === $own->hostname()
                && !str_contains(json_encode($identity), 'Portada B'),
            'permissions'=>$permissions,
            'bad_permission'=>denied(fn()=>PermissionCatalog::normalize(['catalog.publish'])),
            'bad_module'=>denied(fn()=>PermissionCatalog::normalizeModule('forged')),
        ],
    ], JSON_THROW_ON_ERROR);
}
"""


@lru_cache(maxsize=1)
def php_evidence() -> dict[str, object]:
    php = shutil.which("php")
    if php is None or any(not (ROOT / path).is_file() for path in SOURCES):
        raise AssertionError("UNKNOWN: PHP o implementación real ausentes")
    result = subprocess.run(
        [php, "-d", "allow_url_fopen=0", "-r", PROBE],
        cwd=ROOT, capture_output=True, text=True, check=False, timeout=30,
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError("UNKNOWN: fallo PHP " + result.stderr[-900:])
    try:
        data = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: salida PHP inválida") from exc
    if not isinstance(data, dict) or set(data) != {"brand", "locale", "tenant"}:
        raise AssertionError("UNKNOWN: prueba incompleta")
    return data


class CoberturaCondor30Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        evidence = php_evidence()
        brand, locale, tenant = (evidence[key] for key in ("brand", "locale", "tenant"))
        # SPEC-BASE-0: identidad pública y fuente de versión reales, no DNS en vivo.
        self.assertEqual("Empresa de prueba A", brand["name"])
        self.assertEqual("empresa-a", brand["slug"])
        self.assertEqual("Portada A", brand["headline"])
        self.assertEqual("https://empresa-a.example.invalid/", brand["canonical"])
        self.assertRegex(brand["version"], r"^\d+\.\d+\.\d+")
        # SPEC-BASE-1: filtros y errores de interfaz en español; no toda la localización.
        self.assertEqual(("telas", "mayoristas", 2),
                         (locale["term"], locale["category"], locale["page"]))
        self.assertEqual("?q=telas&categoria=mayoristas&pagina=3", locale["url"])
        # SPEC-BASE-4: alcance tenant real en proyección + catálogo de permisos.
        self.assertEqual(["catalog.create", "catalog.view"], tenant["permissions"])
        self.assertTrue(tenant["foreign_hidden"])
        self.assertEqual(1, len(brand["domains"]))

    def test_falla_cerrado(self) -> None:
        evidence = php_evidence()
        locale, tenant = evidence["locale"], evidence["tenant"]
        for key in ("bad_page", "forged_type", "unknown_category"):
            with self.subTest(locale=key):
                self.assertIs(locale[key], True)
        for key in ("bad_permission", "bad_module", "foreign_hidden"):
            with self.subTest(authorization=key):
                self.assertIs(tenant[key], True)
        # Ausencia o duplicidad de dominio primario nunca publica el host ajeno.
        safe = "https://www.condorapp.com.co/empresa-a"
        self.assertEqual(safe, tenant["fallback"])
        self.assertEqual(safe, tenant["duplicate"])

    def test_sin_datos_reales(self) -> None:
        self.assertTrue(all((ROOT / path).is_file() for path in SOURCES))
        self.assertIn("example.invalid", PROBE)
        self.assertIn("FixtureManager", PROBE)
        for forbidden in ("curl_", "file_get_contents(", "mysqli_", "PDO(",
                          "Bearer ", "getenv(", "https://api.github.com"):
            self.assertNotIn(forbidden, PROBE)
        self.assertNotIn("matriz-cobertura", PROBE)
        self.assertTrue(all(php_evidence().values()))


if __name__ == "__main__":
    unittest.main()
