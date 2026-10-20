#!/usr/bin/env python3
"""#729 AC-01: execute authenticated Symfony customer HTTP isolation checks.

All fixtures are synthetic and persist only to the test database. The test
fails closed if PHP, the Symfony test runner, or the actual HTTP contract fails.
"""
from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/CustomerController.php"

HTTP_SMOKE = r"""<?php
declare(strict_types=1);

use App\Domain\Commerce\Entity\CommercialCategory;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CustomerIsolationHttpSmokeTest extends WebTestCase
{
    public function testCustomerListsAndMutationsAreTenantScoped(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = strtolower(bin2hex(random_bytes(5)));

        $a = new Tenant('Clientes A '.$suffix, 'clientes-a-'.$suffix);
        $legalA = new LegalEntity($a, 'Clientes A SAS', null, true);
        $branchA = new Branch($a, 'Principal', 'principal-'.$suffix, $legalA, true);
        $branchHidden = new Branch($a, 'Oculta', 'oculta-'.$suffix, $legalA);
        $customerA = new Customer($a, 'Cliente visible '.$suffix);
        $categoryA = new CommercialCategory($a, 'Categoría visible', 'local-'.$suffix);
        $owner = new User('customer-owner-'.$suffix.'@example.test', 'Owner');
        $member = new Membership($a, $owner, Membership::ROLE_OWNER);

        $b = new Tenant('Clientes B '.$suffix, 'clientes-b-'.$suffix);
        $legalB = new LegalEntity($b, 'Clientes B SAS', null, true);
        $branchB = new Branch($b, 'Ajena', 'ajena-'.$suffix, $legalB, true);
        $customerB = new Customer($b, 'CLIENTE_PRIVADO_'.$suffix);
        $categoryB = new CommercialCategory($b, 'CATEGORIA_PRIVADA', 'foreign-'.$suffix);

        $viewer = new User('customer-viewer-'.$suffix.'@example.test', 'Viewer');
        $viewerMember = new Membership($a, $viewer, 'ADMIN');
        $readOnly = new Role($a, 'Solo clientes '.$suffix, ['customers.view']);
        $assignment = new BranchRoleAssignment($viewerMember, $branchA, $readOnly);

        foreach ([
            $a, $legalA, $branchA, $branchHidden, $customerA, $categoryA,
            $owner, $member, $b, $legalB, $branchB, $customerB, $categoryB,
            $viewer, $viewerMember, $readOnly, $assignment,
        ] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $base = '/api/v1/branches/'.$branchA->id().'/customers';
        $client->loginUser($owner);
        $page = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $csrf = $page->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($csrf);
        self::assertNotSame('', $csrf);
        $headers = ['HTTP_X_CSRF_TOKEN' => $csrf];

        // GET is a real HTTP request: the other tenant's customers and categories
        // must not be returned, even when their entities exist in the same DB.
        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(200);
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true, 512, JSON_THROW_ON_ERROR,
        );
        self::assertSame([$customerA->id()], array_column($payload['customers'], 'id'));
        self::assertSame([$categoryA->id()], array_column($payload['categories'], 'id'));
        self::assertStringNotContainsString(
            'CLIENTE_PRIVADO_', (string) $client->getResponse()->getContent(),
        );
        $client->request('GET', '/api/v1/branches/'.$branchB->id().'/customers');
        self::assertResponseStatusCodeSame(404);

        // A foreign customer and category must be indistinguishable from missing
        // resources for PATCH/DELETE and assignment through the local branch.
        $client->jsonRequest('PATCH', $base.'/'.$customerB->id(), [
            'name' => 'TAKEOVER_'.$suffix,
        ], $headers);
        self::assertResponseStatusCodeSame(404);
        $client->request('DELETE', $base.'/'.$customerB->id(), [], [], $headers);
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest('POST', $base, [
            'name' => 'Cliente con categoría ajena',
            'category_id' => $categoryB->id(),
        ], $headers);
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest('PATCH', $base.'/categories/'.$categoryB->id(), [
            'name' => 'TAKEOVER_CATEGORY',
        ], $headers);
        self::assertResponseStatusCodeSame(404);
        $client->request(
            'DELETE', $base.'/categories/'.$categoryB->id(), [], [], $headers,
        );
        self::assertResponseStatusCodeSame(404);
        $manager->refresh($customerB);
        $manager->refresh($categoryB);
        self::assertSame('CLIENTE_PRIVADO_'.$suffix, $customerB->name());
        self::assertSame('CATEGORIA_PRIVADA', $categoryB->name());

        // Required name, expected JSON shape and field allowlist are enforced
        // by the server, never by a client-side-only validator.
        foreach ([
            [], ['name' => null], ['name' => 17],
            ['name' => ''], ['name' => str_repeat('x', 181)],
            ['name' => 'Permitido', 'tenant_id' => $b->id()],
        ] as $invalid) {
            $client->jsonRequest('POST', $base, $invalid, $headers);
            self::assertResponseStatusCodeSame(422);
        }
        $client->request('POST', $base, [], [], $headers, '[]');
        self::assertResponseStatusCodeSame(400);
        $client->jsonRequest('POST', $base, ['name' => 'Sin CSRF']);
        self::assertResponseStatusCodeSame(403);

        // A valid local create/update still succeeds; the test does not merely
        // assert that all endpoints reject everything.
        $client->jsonRequest('POST', $base, [
            'name' => 'Cliente válido '.$suffix, 'category_id' => $categoryA->id(),
        ], $headers);
        self::assertResponseStatusCodeSame(201);
        $created = json_decode(
            (string) $client->getResponse()->getContent(),
            true, 512, JSON_THROW_ON_ERROR,
        )['customer'];
        self::assertSame($categoryA->id(), $created['commercial_category_id']);
        $client->jsonRequest('PATCH', $base.'/'.$created['id'], [
            'name' => 'Cliente actualizado '.$suffix,
        ], $headers);
        self::assertResponseStatusCodeSame(200);

        // Branch permissions: read-only users cannot mutate, hidden branches
        // remain forbidden, and anonymous sessions cannot enumerate tenants.
        $client->loginUser($viewer);
        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(200);
        $client->request('GET', '/api/v1/branches/'.$branchHidden->id().'/customers');
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('PATCH', $base.'/'.$customerA->id(), [
            'name' => 'Prohibido',
        ], $headers);
        self::assertResponseStatusCodeSame(403);
        $client->restart();
        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(403);
    }
}
"""


class ClientesAislamientoHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(CONTROLLER.is_file(), str(CONTROLLER))
        php = shutil.which("php")
        self.assertIsNotNone(php, "Se necesita PHP CLI real para AC-01")
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "Se necesita PHPUnit para HTTP real")

        syntax = subprocess.run(
            [php, "-l", str(CONTROLLER)], cwd=ROOT,
            capture_output=True, text=True, check=False, timeout=20,
        )
        self.assertEqual(0, syntax.returncode, syntax.stdout + syntax.stderr)

        with tempfile.TemporaryDirectory(prefix="condor-customers-http-") as tmp:
            fixture = Path(tmp) / "CustomerIsolationHttpSmokeTest.php"
            fixture.write_text(HTTP_SMOKE, encoding="utf-8")
            lint = subprocess.run(
                [php, "-l", str(fixture)], cwd=ROOT,
                capture_output=True, text=True, check=False, timeout=20,
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            result = subprocess.run(
                [str(runner), str(fixture)], cwd=ROOT,
                capture_output=True, text=True, check=False, timeout=120,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
