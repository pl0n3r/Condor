#!/usr/bin/env python3
"""#761 AC-01: Symfony HTTP contract for tenant public pages (synthetic data only)."""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/TenantPublicController.php"
RESOLVER = ROOT / "src/Infrastructure/Tenancy/TenantResolver.php"
TEMPLATE = ROOT / "templates/tenant/index.html.twig"

HTTP_SMOKE = r"""<?php
declare(strict_types=1);

use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\StorefrontProfile;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TenantPublicContractHttpSmokeTest extends WebTestCase
{
    public function testPublicPagesResolveOnlyTheirTenantOnPlatformHost(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $suffix = bin2hex(random_bytes(5));

        $tenantA = new Tenant('Comercio A '.$suffix, 'public-a-'.$suffix);
        $tenantB = new Tenant('Comercio B '.$suffix, 'public-b-'.$suffix);
        $profileA = new StorefrontProfile(
            $tenantA,
            'VISIBLE_A_'.$suffix.' <script>INYECCION_A</script>',
            'Descripcion publicada A '.$suffix,
        );
        $profileB = new StorefrontProfile(
            $tenantB, 'VISIBLE_B_'.$suffix, 'Descripcion publicada B '.$suffix,
        );
        $internalA = new User(
            'internal-a-'.$suffix.'@example.test', 'No publicado A',
        );
        $internalB = new User(
            'internal-b-'.$suffix.'@example.test', 'No publicado B',
        );
        $membershipA = new Membership(
            $tenantA, $internalA, Membership::ROLE_OWNER,
        );
        $membershipB = new Membership(
            $tenantB, $internalB, Membership::ROLE_OWNER,
        );

        foreach ([
            $tenantA, $tenantB, $profileA, $profileB, $internalA, $internalB,
            $membershipA, $membershipB,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $host = ['HTTP_HOST' => 'www.condorapp.com.co'];
        $client->request('GET', '/'.$tenantA->slug(), server: $host);
        self::assertResponseStatusCodeSame(200);
        $pageA = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('VISIBLE_A_'.$suffix, $pageA);
        self::assertStringContainsString('data-tenant-slug="'.$tenantA->slug().'"', $pageA);
        self::assertStringNotContainsString('VISIBLE_B_'.$suffix, $pageA);
        self::assertStringNotContainsString('internal-a-'.$suffix, $pageA);
        self::assertStringNotContainsString('internal-b-'.$suffix, $pageA);
        self::assertStringNotContainsString('<script>INYECCION_A</script>', $pageA);
        self::assertStringContainsString('&lt;script&gt;INYECCION_A&lt;/script&gt;', $pageA);

        $client->request('GET', '/'.$tenantB->slug(), server: $host);
        self::assertResponseStatusCodeSame(200);
        $pageB = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('VISIBLE_B_'.$suffix, $pageB);
        self::assertStringNotContainsString('VISIBLE_A_'.$suffix, $pageB);
        self::assertStringNotContainsString('internal-b-'.$suffix, $pageB);

        // A valid-looking slug without a registered tenant cannot be guessed.
        $client->request('GET', '/unknown-'.$suffix, server: $host);
        self::assertResponseStatusCodeSame(404);

        // This path belongs to the platform hostname, not arbitrary Host input.
        $client->request(
            'GET', '/'.$tenantA->slug(),
            server: ['HTTP_HOST' => 'unrecognized-'.$suffix.'.example.test'],
        );
        self::assertResponseStatusCodeSame(404);

        $client->request(
            'GET', '/'.$tenantA->slug().'?page=0', server: $host,
        );
        self::assertResponseStatusCodeSame(400);
        $client->request(
            'GET', '/'.$tenantA->slug().'?page=1001', server: $host,
        );
        self::assertResponseStatusCodeSame(400);
    }
}
"""


class TenantPublicoContratoHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(CONTROLLER.is_file())
        self.assertTrue(RESOLVER.is_file())
        self.assertTrue(TEMPLATE.is_file())
        source = CONTROLLER.read_text(encoding="utf-8")
        resolver = RESOLVER.read_text(encoding="utf-8")
        template = TEMPLATE.read_text(encoding="utf-8")

        self.assertIn("TenantResolver::isPlatformHost($request->getHost())", source)
        self.assertIn("'slug' => $tenant_slug", source)
        self.assertIn("$current->id() !== $tenant->id()", source)
        self.assertIn("new NotFoundHttpException()", source)
        self.assertIn("PublicCatalogPresentation::MAX_PAGE", source)
        self.assertIn("'verified' => true", resolver)
        self.assertIn("{{ storefront.headline ?: storefront.name }}", template)
        self.assertIn("{{ storefront.description }}", template)
        self.assertNotIn("membership", template.lower())

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI required for HTTP acceptance")
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "Symfony/PHPUnit dependencies required")
        with tempfile.TemporaryDirectory(prefix="condor-public-http-") as directory:
            fixture = Path(directory) / "TenantPublicContractHttpSmokeTest.php"
            fixture.write_text(HTTP_SMOKE, encoding="utf-8")
            lint = subprocess.run(
                [php, "-l", str(fixture)],
                cwd=ROOT, text=True, capture_output=True,
                check=False, timeout=30,
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            result = subprocess.run(
                [str(runner), str(fixture)],
                cwd=ROOT, text=True, capture_output=True,
                check=False, timeout=180,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
