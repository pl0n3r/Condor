#!/usr/bin/env python3
"""#757: comprobar el aislamiento HTTP de sedes con Symfony real y datos sintéticos.

El PHPUnit temporal permite mantener la reserva V3 en una sola ruta. No se
utilizan servicios externos, identidades de clientes ni datos productivos.
"""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/BranchAccessController.php"
RUNNER = ROOT / "vendor/bin/simple-phpunit"

HTTP_FIXTURE = r"""<?php
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

final class BranchAccessHttpSmokeTest extends WebTestCase
{
    public function testAssignedBranchesOnlyAndTenantIsolation(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = bin2hex(random_bytes(5));

        $tenantA = new Tenant('Sedes A '.$suffix, 'sedes-a-'.$suffix);
        $legalA = new LegalEntity($tenantA, 'Sedes A SAS', null, true);
        $branchA = new Branch($tenantA, 'Asignada', 'asignada-'.$suffix, $legalA, true);
        $branchHidden = new Branch($tenantA, 'No asignada', 'oculta-'.$suffix, $legalA);
        $ownerA = new User('branch-owner-'.$suffix.'@example.test', 'Owner A');
        $ownerMembership = new Membership($tenantA, $ownerA, Membership::ROLE_OWNER);
        $viewer = new User('branch-viewer-'.$suffix.'@example.test', 'Viewer A');
        $viewerMembership = new Membership($tenantA, $viewer, 'ADMIN');
        $visibleRole = new Role($tenantA, 'Consulta sedes '.$suffix, [
            'roles.view', 'users.view',
        ]);
        $assignment = new BranchRoleAssignment($viewerMembership, $branchA, $visibleRole);

        $tenantB = new Tenant('Sedes B '.$suffix, 'sedes-b-'.$suffix);
        $legalB = new LegalEntity($tenantB, 'Sedes B SAS', null, true);
        $branchB = new Branch($tenantB, 'Privada', 'privada-'.$suffix, $legalB, true);
        $ownerB = new User('foreign-owner-'.$suffix.'@example.test', 'Owner B');
        $foreignMembership = new Membership($tenantB, $ownerB, Membership::ROLE_OWNER);
        $foreignRole = new Role($tenantB, 'ROL_PRIVADO_'.$suffix, ['roles.view']);

        foreach ([
            $tenantA, $legalA, $branchA, $branchHidden, $ownerA,
            $ownerMembership, $viewer, $viewerMembership, $visibleRole,
            $assignment, $tenantB, $legalB, $branchB, $ownerB,
            $foreignMembership, $foreignRole,
        ] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $rolesA = '/api/v1/branches/'.$branchA->id().'/roles';
        $membersA = '/api/v1/branches/'.$branchA->id().'/memberships';
        $rolesB = '/api/v1/branches/'.$branchB->id().'/roles';
        $membersB = '/api/v1/branches/'.$branchB->id().'/memberships';

        // Sin sesión, el firewall API exige autenticación: HTTP 401.
        foreach ([$rolesA, $membersA] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(401);
        }

        // Un owner puede consultar su propio tenant, nunca el segundo.
        $client->loginUser($ownerA);
        $client->request('GET', $rolesA);
        self::assertResponseStatusCodeSame(200);
        $roles = json_decode(
            (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR,
        );
        self::assertSame([$visibleRole->id()], array_column($roles['roles'], 'id'));
        self::assertArrayHasKey('catalog', $roles);
        self::assertStringNotContainsString(
            'ROL_PRIVADO_', (string) $client->getResponse()->getContent(),
        );

        $client->request('GET', $membersA);
        self::assertResponseStatusCodeSame(200);
        $members = json_decode(
            (string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR,
        );
        $actual = array_column($members['memberships'], 'id');
        $expected = [$ownerMembership->id(), $viewerMembership->id()];
        sort($actual);
        sort($expected);
        self::assertSame($expected, $actual);
        self::assertNotContains($foreignMembership->id(), $actual);
        self::assertSame(
            [$visibleRole->id()],
            $this->roleIdsFor($members['memberships'], $viewerMembership->id()),
        );

        foreach ([$rolesB, $membersB] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString(
                'ROL_PRIVADO_', (string) $client->getResponse()->getContent(),
            );
        }

        // La membresía no otorga acceso a todas las sedes: solo a la asignada.
        $client->loginUser($viewer);
        foreach ([$rolesA, $membersA] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(200);
        }
        foreach ([
            '/api/v1/branches/'.$branchHidden->id().'/roles',
            '/api/v1/branches/'.$branchHidden->id().'/memberships',
        ] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(403);
        }
        foreach ([$rolesB, $membersB] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404);
        }

        // El mismo cliente sin sesión deja de ver incluso su sede asignada.
        $client->restart();
        $client->request('GET', $rolesA);
        self::assertResponseStatusCodeSame(401);
    }

    /** @param list<array<string, mixed>> $members */
    private function roleIdsFor(array $members, string $membershipId): array
    {
        foreach ($members as $member) {
            if ($member['id'] === $membershipId) {
                return $member['role_ids'];
            }
        }

        self::fail('No se encontró la membresía de prueba.');
    }
}
"""


class SedesAccesoHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(CONTROLLER.is_file(), str(CONTROLLER))
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI real es obligatorio para AC-01")
        self.assertTrue(RUNNER.is_file(), "PHPUnit real es obligatorio para AC-01")

        with tempfile.TemporaryDirectory(prefix="condor-branch-http-") as directory:
            fixture = Path(directory) / "BranchAccessHttpSmokeTest.php"
            fixture.write_text(HTTP_FIXTURE, encoding="utf-8")
            lint = subprocess.run(
                [php, "-l", str(fixture)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=25,
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            result = subprocess.run(
                [str(RUNNER), str(fixture)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=180,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)
            self.assertRegex(result.stdout + result.stderr, r"OK\s*\(\s*1 test,")


if __name__ == "__main__":
    unittest.main()
