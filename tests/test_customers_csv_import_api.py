#!/usr/bin/env python3
"""Condor #809: aceptación HTTP real con datos exclusivamente sintéticos."""

from __future__ import annotations

import json
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IMPORTER = ROOT / "src/Application/Commerce/CustomerCsvImporter.php"
CONTROLLER = ROOT / "src/Http/Controller/CustomerImportController.php"
DATOS = ROOT / "datos.yml"

FIXTURE = r'''<?php
declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Commerce\Entity\Customer;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CustomerImportSyntheticHttpTest extends WebTestCase
{
    public function testPreviewCommitDuplicatePermissionAndTenant(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $suffix = bin2hex(random_bytes(5));

        $a = new Tenant('Import A '.$suffix, 'csv-customers-a-'.$suffix);
        $branchA = new Branch($a, 'Principal', 'csv-a-'.$suffix, null, true);
        $ownerA = new User('csv-a-'.$suffix.'@example.test', 'Owner A');
        $ownerMember = new Membership($a, $ownerA, Membership::ROLE_OWNER);

        $b = new Tenant('Import B '.$suffix, 'csv-customers-b-'.$suffix);
        $branchB = new Branch($b, 'Ajena', 'csv-b-'.$suffix, null, true);
        $foreign = new Customer($b, 'CLIENTE_PRIVADO_'.$suffix, 'ana@example.test');

        $viewer = new User('csv-viewer-'.$suffix.'@example.test', 'Viewer');
        $viewerMember = new Membership($a, $viewer, 'ADMIN');
        $role = new Role($a, 'Solo ver clientes', ['customers.view']);
        $access = new BranchRoleAssignment($viewerMember, $branchA, $role);
        foreach ([
            $a, $branchA, $ownerA, $ownerMember,
            $b, $branchB, $foreign, $viewer, $viewerMember, $role, $access,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $csv = "name,email,phone\n"
            ."Ana,ANA@EXAMPLE.TEST,3101\n"
            ."Beto,beto@example.test,3102\n"
            ."Repetido,ana@example.test,3103\n"
            ."Inválido,no-es-correo,3104\n";
        $payload = ['branch_id' => $branchA->id(), 'csv' => $csv];
        $client->loginUser($ownerA);
        $csrf = $this->csrf($client);
        $headers = ['HTTP_X_CSRF_TOKEN' => $csrf];

        $client->jsonRequest('POST', '/api/customers/import/preview', $payload, $headers);
        self::assertResponseIsSuccessful();
        $preview = $this->json($client);
        self::assertSame(4, $preview['total']);
        self::assertSame(2, $preview['valid']);
        self::assertSame(2, $preview['errors']);
        self::assertSame('error', $preview['rows'][2]['status']);
        self::assertSame('error', $preview['rows'][3]['status']);
        self::assertSame('ana@example.test', $preview['rows'][0]['email']);
        self::assertCount(0, $em->getRepository(Customer::class)->findBy(['tenant' => $a]));

        $client->jsonRequest('POST', '/api/customers/import/commit', $payload, $headers);
        self::assertResponseIsSuccessful();
        $committed = $this->json($client);
        self::assertSame(2, $committed['created']);
        self::assertSame(2, $committed['errors']);
        self::assertCount(2, $em->getRepository(Customer::class)->findBy(['tenant' => $a]));
        self::assertSame(
            'CLIENTE_PRIVADO_'.$suffix,
            $em->getRepository(Customer::class)->findOneBy(['tenant' => $b])->name(),
        );

        $client->jsonRequest('POST', '/api/customers/import/commit', $payload, $headers);
        self::assertResponseIsSuccessful();
        $again = $this->json($client);
        self::assertSame(0, $again['created']);
        self::assertSame(2, $again['existing']);
        self::assertCount(2, $em->getRepository(Customer::class)->findBy(['tenant' => $a]));

        $client->jsonRequest('POST', '/api/customers/import/commit', [
            'branch_id' => $branchB->id(), 'csv' => $csv,
        ], $headers);
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest('POST', '/api/customers/import/preview', $payload);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($viewer);
        $client->jsonRequest('POST', '/api/customers/import/preview', $payload, [
            'HTTP_X_CSRF_TOKEN' => $this->csrf($client),
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($ownerA);
        $client->jsonRequest('POST', '/api/customers/import/preview', [
            'branch_id' => $branchA->id(),
            'csv' => "name,email\nMal,mal@example.test\n",
            'tenant_id' => $b->id(),
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf($client)]);
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('POST', '/api/customers/import/preview', [
            'branch_id' => $branchA->id(),
            'csv' => "name,email\n".str_repeat('A,valid@example.test\n', 501),
        ], ['HTTP_X_CSRF_TOKEN' => $this->csrf($client)]);
        self::assertResponseStatusCodeSame(422);
    }

    private function csrf($client): string
    {
        $page = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $value = $page->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($value);
        return $value;
    }

    /** @return array<string, mixed> */
    private function json($client): array
    {
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        return $payload;
    }
}
'''


class ClientesImportCsvApiTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        for path in (IMPORTER, CONTROLLER, DATOS):
            self.assertTrue(path.is_file(), str(path))
        importer = IMPORTER.read_text(encoding="utf-8")
        controller = CONTROLLER.read_text(encoding="utf-8")
        privacy = json.loads(DATOS.read_text(encoding="utf-8"))
        contact = next(
            item for item in privacy["treatments"] if item["id"] == "customer_contact"
        )
        self.assertTrue({"name", "email", "phone", "notes"}.issubset(contact["fields"]))
        self.assertNotIn("document", contact["fields"])
        self.assertEqual("customer_management", contact["purpose"])
        for marker in (
            "'/api/customers/import/preview'",
            "'/api/customers/import/commit'",
            "'customers.create'",
            "authorizedBranchScope",
            "requireCsrf",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, controller)
        for marker in (
            "wrapInTransaction",
            "PESSIMISTIC_WRITE",
            "'tenant' => $tenant",
            "'email' => $email",
            "'status' => 'error'",
            "MAX_ROWS = 500",
            "MAX_BYTES = 262144",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, importer)
        self.assertNotIn("'email' => $row['email']", importer.split("new AuditEvent(", 1)[-1])

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI requerido")
        for source in (IMPORTER, CONTROLLER):
            lint = subprocess.run([php, "-l", str(source)], capture_output=True, text=True)
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)

        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.is_file(), "PHPUnit requerido para aceptación HTTP real")
        with tempfile.TemporaryDirectory(prefix="condor-customers-csv-") as folder:
            fixture = Path(folder) / "CustomerImportSyntheticHttpTest.php"
            fixture.write_text(FIXTURE, encoding="utf-8")
            lint = subprocess.run([php, "-l", str(fixture)], capture_output=True, text=True)
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            test = subprocess.run(
                [str(runner), "--configuration", str(ROOT / "phpunit.xml.dist"), str(fixture)],
                cwd=ROOT, capture_output=True, text=True, timeout=180,
            )
            self.assertEqual(0, test.returncode, test.stdout + test.stderr)


if __name__ == "__main__":
    unittest.main()
