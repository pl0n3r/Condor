#!/usr/bin/env python3
"""Condor #1578: authenticated M09 S02/S03 HTTP guards and UI contract."""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
UI = ROOT / "frontend/admin/PurchasingManagement.tsx"
BUNDLE = ROOT / "public/build/admin.js"
CONTROLLER = ROOT / "src/Http/Controller/PurchasingController.php"
SUPPORT = ROOT / "src/Http/Controller/BranchApiSupport.php"
REPORT = ROOT / "docs/verificacion/gest-m09-ui.md"

PHP_HTTP_SMOKE = r'''<?php
declare(strict_types=1);

use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class M09SyntheticPreviewHttpSmokeTest extends WebTestCase
{
    public function testAuthenticatedPreviewScopesAndNegativeHttpStatuses(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $suffix = strtolower(bin2hex(random_bytes(5)));

        $tenant = new Tenant('M09 local '.$suffix, 'm09-local-'.$suffix);
        $entity = new LegalEntity($tenant, 'Local SAS '.$suffix, null, true);
        $branch = new Branch($tenant, 'Principal', 'principal-'.$suffix, $entity, true);
        $hidden = new Branch($tenant, 'Restringida', 'hidden-'.$suffix, $entity, true);
        $foreignTenant = new Tenant('M09 ajeno '.$suffix, 'm09-foreign-'.$suffix);
        $foreignEntity = new LegalEntity($foreignTenant, 'Ajena SAS '.$suffix, null, true);
        $foreignBranch = new Branch($foreignTenant, 'Ajena', 'foreign-'.$suffix, $foreignEntity, true);

        $owner = new User('m09-owner-'.$suffix.'@example.test', 'Owner');
        $ownerMembership = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        $viewer = new User('m09-viewer-'.$suffix.'@example.test', 'Viewer');
        $viewerMembership = new Membership($tenant, $viewer, 'ADMIN');
        $viewRole = new Role($tenant, 'Lectura '.$suffix, ['inventory.view']);
        $viewAssignment = new BranchRoleAssignment($viewerMembership, $branch, $viewRole);
        foreach ([
            $tenant, $entity, $branch, $hidden,
            $foreignTenant, $foreignEntity, $foreignBranch,
            $owner, $ownerMembership, $viewer, $viewerMembership,
            $viewRole, $viewAssignment,
        ] as $item) {
            $em->persist($item);
        }
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $csrf = $crawler->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($csrf);
        self::assertNotSame('', $csrf);

        $scope = [
            'tenant_ref' => 'fixture:tenant/current',
            'legal_entity_ref' => 'fixture:legal-entity/current',
            'site_ref' => 'fixture:site/current',
        ];
        $line = array_merge($scope, [
            'item_ref' => 'fixture:item/paper', 'quantity' => '2',
        ]);
        $base = array_merge($scope, [
            'currency' => 'COP', 'mode' => 'preview_only',
        ]);
        $quote = array_merge($base, [
            'lines' => [array_merge($line, ['unit_price_cop' => '125.50'])],
        ]);
        $request = array_merge($base, ['lines' => [$line]]);
        $baseUrl = '/api/v1/branches/'.$branch->id().'/purchasing';
        $foreignBase = '/api/v1/branches/'.$foreignBranch->id().'/purchasing';
        $endpoints = [
            ['/quotations/preview', $quote, 'quotation_preview', 'SYNTHETIC_PREVIEW'],
            ['/requests/preview', $request, 'request_preview', 'pending'],
        ];

        foreach ($endpoints as [$path, $payload, $responseKey, $expectedStatus]) {
            $url = $baseUrl.$path;
            // Successful response is synthetic only, with no mutation authority.
            $client->jsonRequest('POST', $url, $payload, ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(200);
            $envelope = json_decode((string)$client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($envelope['synthetic_only']);
            $preview = $envelope[$responseKey];
            self::assertSame($expectedStatus, $preview['status']);
            self::assertSame('preview_only', $preview['mode']);
            self::assertSame('COP', $preview['currency']);
            self::assertSame(1, $preview['line_count']);
            foreach (['can_order', 'can_approve', 'can_pay', 'can_dispatch', 'persisted'] as $flag) {
                self::assertFalse($preview[$flag]);
            }
            if ($responseKey === 'quotation_preview') {
                self::assertSame(251, $preview['total_cop']);
            } else {
                self::assertFalse($preview['notified']);
            }

            // Authentication alone is insufficient: CSRF and branch must match.
            $client->jsonRequest('POST', $url, $payload);
            self::assertResponseStatusCodeSame(403);
            $client->jsonRequest('POST', $foreignBase.$path, $payload, ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(404);

            foreach (['tenant_ref', 'legal_entity_ref', 'site_ref'] as $field) {
                $invalid = $payload;
                $invalid[$field] = 'fixture:foreign/current';
                $client->jsonRequest('POST', $url, $invalid, ['HTTP_X_CSRF_TOKEN' => $csrf]);
                self::assertResponseStatusCodeSame(422);
                $invalid = $payload;
                $invalid['lines'][0][$field] = 'fixture:foreign/current';
                $client->jsonRequest('POST', $url, $invalid, ['HTTP_X_CSRF_TOKEN' => $csrf]);
                self::assertResponseStatusCodeSame(422);
            }
            $unexpected = $payload;
            $unexpected['customer_email'] = 'blocked@example.test';
            $client->jsonRequest('POST', $url, $unexpected, ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(422);
            $client->request('POST', $url, [], [], [
                'HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json',
            ], '{invalid-json');
            self::assertResponseStatusCodeSame(400);
        }

        // Viewer has a genuine valid CSRF but does not have inventory.create.
        $client->loginUser($viewer);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $viewerCsrf = $crawler->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($viewerCsrf);
        foreach ($endpoints as [$path, $payload]) {
            $client->jsonRequest('POST', $baseUrl.$path, $payload, ['HTTP_X_CSRF_TOKEN' => $viewerCsrf]);
            self::assertResponseStatusCodeSame(403);
            $client->jsonRequest('POST', '/api/v1/branches/'.$hidden->id().'/purchasing'.$path, $payload, [
                'HTTP_X_CSRF_TOKEN' => $viewerCsrf,
            ]);
            self::assertResponseStatusCodeSame(403);
        }
    }
}
'''


class M09PreviewHttpTests(unittest.TestCase):
    def test_ui_and_bundle_fail_closed(self) -> None:
        ui = UI.read_text(encoding="utf-8")
        bundle = BUNDLE.read_text(encoding="utf-8")
        controller = CONTROLLER.read_text(encoding="utf-8")
        support = SUPPORT.read_text(encoding="utf-8")
        report = REPORT.read_text(encoding="utf-8")
        for route in ("/quotations/preview", "/requests/preview"):
            self.assertIn(route, ui)
            self.assertIn(route, controller)
        for marker in (
            "tenant_ref: 'fixture:tenant/current'",
            "legal_entity_ref: 'fixture:legal-entity/current'",
            "site_ref: 'fixture:site/current'",
            "item_ref: 'fixture:item/paper'",
            "credentials: 'same-origin'",
            "'X-CSRF-Token': csrfToken",
            "response.status === 401 || response.status === 403",
            "envelope?.synthetic_only !== true",
            "value.can_order !== false",
            "value.can_approve !== false",
            "value.can_pay !== false",
            "value.can_dispatch !== false",
            "value.persisted !== false",
            "value.notified !== false",
            "previewRequests.current.quotation++",
            "previewRequests.current.request++",
            "status: 'empty'",
            "status: 'loading'",
            "status: 'denied'",
            "status: 'error'",
            "status: 'ready'",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, ui)
        for marker in (
            "Previews ficticios de compras",
            "Previews sintéticos no disponibles",
            "Sin preview: consulta el ejemplo",
            "Permiso denegado para este preview",
            "Cotización de ejemplo:",
            "Solicitud de ejemplo pendiente:",
        ):
            self.assertIn(marker, bundle)
        for marker in ("requirePurchasingPreviewEnabled()", "requireCsrf($request)",
                       "authorizedBranchScope($branchId, 'inventory.create')",
                       "CONDOR_PURCHASING_SYNTHETIC_API", "CONDOR_PRODUCTION_STAGE"):
            self.assertIn(marker, controller)
        self.assertIn("private function authorizedBranchScope(", support)
        self.assertIn("'tenant' => $tenant", support)
        self.assertIn("GEST-M09-S02", report)
        self.assertIn("GEST-M09-S03", report)

    def test_real_authenticated_http_guards(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "CLI PHP requerido para validar HTTP real.")
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "PHPUnit instalado requerido para HTTP autenticado.")
        with tempfile.TemporaryDirectory(prefix="condor-m09-http-") as directory:
            file = Path(directory) / "M09SyntheticPreviewHttpSmokeTest.php"
            file.write_text(PHP_HTTP_SMOKE, encoding="utf-8")
            lint = subprocess.run([php, "-l", str(file)], capture_output=True, text=True, check=False)
            self.assertEqual(lint.returncode, 0, lint.stdout + lint.stderr)
            result = subprocess.run(
                [str(runner), str(file)], cwd=ROOT,
                capture_output=True, text=True, check=False, timeout=150,
            )
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
