#!/usr/bin/env python3
"""Condor #759: negative HTTP acceptance for invitation activation.

The one PHPUnit WebTestCase is created outside the checkout and uses only a
disposable Symfony/MariaDB test database. No customer identities, real invites,
remote APIs, or production state are accessed.
"""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/InvitationActivationController.php"
ACCEPTANCE = ROOT / "src/Application/Identity/AcceptAccountInvitation.php"
RUNNER = ROOT / "vendor/bin/simple-phpunit"

PHP_SCENARIO = r"""<?php
declare(strict_types=1);

use App\Domain\Identity\Entity\AccountInvitation;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InvitationActivationNegativesSmokeTest extends WebTestCase
{
    public function testExpiredConsumedAndTamperedTokensAreIndistinguishable(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $suffix = bin2hex(random_bytes(6));
        $a = new Tenant('Invitación A '.$suffix, 'invitation-a-'.$suffix);
        $b = new Tenant('Invitación B '.$suffix, 'invitation-b-'.$suffix);
        $userA = new User('invitation-a-'.$suffix.'@example.test', 'NOMBRE_A_'.$suffix);
        $userB = new User('invitation-b-'.$suffix.'@example.test', 'NOMBRE_B_PRIVADO_'.$suffix);
        $expiredUser = new User('invitation-expired-'.$suffix.'@example.test', 'EXPIRADO_PRIVADO_'.$suffix);
        $usedUser = new User('invitation-used-'.$suffix.'@example.test', 'USADO_PRIVADO_'.$suffix);
        foreach ([$userA, $userB, $expiredUser, $usedUser] as $user) {
            $user->deactivate();
        }

        $tokenA = bin2hex(random_bytes(32));
        $tokenB = bin2hex(random_bytes(32));
        $tokenExpired = bin2hex(random_bytes(32));
        $tokenUsed = bin2hex(random_bytes(32));
        $validUntil = new DateTimeImmutable('+1 day');
        $expiredAt = new DateTimeImmutable('-1 day');
        $inviteA = new AccountInvitation(
            $userA, $a, AccountInvitation::KIND_TENANT_MEMBER,
            hash('sha256', $tokenA), $validUntil, $userA->id(),
        );
        $inviteB = new AccountInvitation(
            $userB, $b, AccountInvitation::KIND_TENANT_MEMBER,
            hash('sha256', $tokenB), $validUntil, $userB->id(),
        );
        $inviteExpired = new AccountInvitation(
            $expiredUser, $a, AccountInvitation::KIND_TENANT_MEMBER,
            hash('sha256', $tokenExpired), $expiredAt, $userA->id(),
        );
        $inviteUsed = new AccountInvitation(
            $usedUser, $b, AccountInvitation::KIND_TENANT_MEMBER,
            hash('sha256', $tokenUsed), $validUntil, $userB->id(),
        );
        $inviteUsed->consume(new DateTimeImmutable('now'));
        foreach ([
            $a, $b, $userA, $userB, $expiredUser, $usedUser,
            $inviteA, $inviteB, $inviteExpired, $inviteUsed,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $path = static fn (string $token): string => '/activar-cuenta/'.$token;
        $validA = $path($tokenA);
        $validB = $path($tokenB);
        $expired = $path($tokenExpired);
        $consumed = $path($tokenUsed);
        $tampered = $path(($tokenB[0] === 'a' ? 'b' : 'a').substr($tokenB, 1));
        $unknown = $path(hash('sha256', 'not-issued-'.$suffix));
        $password = 'Synthetic-Password-Only-123';

        // Positive GET control: both distinct tenants can reach *their own*
        // unexpired bearer invitation, but never a different user's identity.
        $client->request('GET', $validA);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('NOMBRE_A_'.$suffix, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('NOMBRE_B_PRIVADO_', (string) $client->getResponse()->getContent());
        $client->request('GET', $validB);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('NOMBRE_B_PRIVADO_'.$suffix, (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('NOMBRE_A_', (string) $client->getResponse()->getContent());

        // Expired, previously consumed, modified and never-issued tokens are
        // opaque: same generic 410, with no account names or email enumeration.
        foreach ([$expired, $consumed, $tampered, $unknown] as $invalid) {
            $client->request('GET', $invalid);
            self::assertResponseStatusCodeSame(410);
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Este enlace ya no está disponible.', $html);
            self::assertStringContainsString('Solicita una nueva invitación.', $html);
            foreach ([
                'NOMBRE_A_', 'NOMBRE_B_PRIVADO_', 'EXPIRADO_PRIVADO_',
                'USADO_PRIVADO_', '@example.test',
            ] as $private) {
                self::assertStringNotContainsString($private, $html);
            }

            // POST denial must occur before any CSRF/password operation.
            $client->request('POST', $invalid, [
                'password' => $password,
                'password_confirmation' => $password,
            ]);
            self::assertResponseStatusCodeSame(410);
        }
        // Invalid token format is denied by the route before account lookup.
        $client->request('GET', '/activar-cuenta/not-a-hex-token');
        self::assertResponseStatusCodeSame(404);
        $em->refresh($userB);
        $em->refresh($userA);
        self::assertFalse($userB->isActive());
        self::assertFalse($userA->isActive());
        self::assertSame('', $userB->getPassword());

        // Actually activate A through HTTP with its real form CSRF token.
        // A second request with the same token must now be rejected (replay).
        $page = $client->request('GET', $validA);
        self::assertResponseStatusCodeSame(200);
        $csrf = $page->filter('input[name="_csrf_token"]')->attr('value');
        self::assertIsString($csrf);
        self::assertNotSame('', $csrf);
        $client->request('POST', $validA, [
            'password' => $password,
            'password_confirmation' => $password,
            '_csrf_token' => $csrf,
        ]);
        self::assertResponseRedirects();
        $em->refresh($userA);
        $em->refresh($inviteA);
        self::assertTrue($userA->isActive());
        self::assertNotSame('', $userA->getPassword());
        self::assertNotNull($inviteA->consumedAt());

        foreach (['GET', 'POST'] as $method) {
            $client->request($method, $validA, [
                'password' => $password,
                'password_confirmation' => $password,
            ]);
            self::assertResponseStatusCodeSame(410);
            self::assertStringNotContainsString(
                'NOMBRE_A_', (string) $client->getResponse()->getContent(),
            );
        }
        $em->refresh($userB);
        $em->refresh($inviteB);
        self::assertFalse($userB->isActive());
        self::assertSame('', $userB->getPassword());
        self::assertNull($inviteB->consumedAt());
    }
}
"""


class InvitacionesNegativasHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        for required_source in (CONTROLLER, ACCEPTANCE):
            self.assertTrue(required_source.is_file(), str(required_source))

        interpreter = shutil.which("php")
        self.assertIsNotNone(interpreter, "PHP CLI obligatorio para HTTP real")
        self.assertTrue(RUNNER.is_file(), "PHPUnit real obligatorio para AC-01")

        with tempfile.TemporaryDirectory(prefix="condor-invitation-http-") as directory:
            scenario = Path(directory) / "InvitationActivationNegativesSmokeTest.php"
            scenario.write_text(PHP_SCENARIO, encoding="utf-8")
            stages = (
                ("php-syntax", [interpreter, "-l", str(scenario)], 25),
                ("http-acceptance", [str(RUNNER), str(scenario)], 180),
            )
            execution_log = {}
            for stage, command, limit in stages:
                completed = subprocess.run(
                    command, cwd=ROOT, check=False,
                    capture_output=True, text=True, timeout=limit,
                )
                evidence = completed.stdout + completed.stderr
                self.assertEqual(
                    0, completed.returncode, f"{stage} failed:\n{evidence}",
                )
                execution_log[stage] = evidence

            # A successful phpunit command alone might have discovered no tests.
            self.assertRegex(
                execution_log["http-acceptance"], r"OK\s*\(\s*1 test,",
            )

if __name__ == "__main__":
    unittest.main()
