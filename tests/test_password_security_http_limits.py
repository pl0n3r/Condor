#!/usr/bin/env python3
"""AC-01 #733: real HTTP negative tests for password recovery with synthetic identities."""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/PasswordSecurityController.php"
RATE_LIMITS = ROOT / "config/packages/rate_limiter.yaml"

PHP_HTTP_NEGATIVES = r'''<?php
declare(strict_types=1);

use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\PasswordResetToken;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordHttpLimitsSmokeTest extends WebTestCase
{
    public function testEnumerationThrottlingAndExpiredResetOverRealHttp(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $suffix = bin2hex(random_bytes(6));
        $tenantA = new Tenant('Password A '.$suffix, 'password-a-'.$suffix);
        $tenantB = new Tenant('Password B '.$suffix, 'password-b-'.$suffix);
        $known = new User('password-a-'.$suffix.'@example.test', 'Owner A');
        $expiredUser = new User('password-b-'.$suffix.'@example.test', 'Owner B');
        $known->setPasswordHash($hasher->hashPassword($known, 'Original-password-A-123!'));
        $expiredUser->setPasswordHash($hasher->hashPassword($expiredUser, 'Original-password-B-123!'));
        $memberA = new Membership($tenantA, $known, Membership::ROLE_OWNER);
        $memberB = new Membership($tenantB, $expiredUser, Membership::ROLE_OWNER);

        foreach ([$tenantA, $tenantB, $known, $expiredUser, $memberA, $memberB] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $resetKnown = null;
        $resetExpired = null;
        try {
            // The two synthetic tenants cannot be distinguished by the public reply.
            $client->setServerParameters(['REMOTE_ADDR' => '198.51.100.51']);
            $requestPage = $client->request('GET', '/admin/recuperar-contrasena');
            self::assertResponseStatusCodeSame(200);
            $csrf = self::csrf($requestPage);
            $knownMessage = self::requestRecovery($client, $known->email(), $csrf);
            $resetKnown = $manager->getRepository(PasswordResetToken::class)
                ->findOneBy(['user' => $known]);
            self::assertInstanceOf(PasswordResetToken::class, $resetKnown);

            $client->setServerParameters(['REMOTE_ADDR' => '198.51.100.52']);
            $unknown = 'not-registered-'.$suffix.'@example.test';
            $unknownMessage = self::requestRecovery($client, $unknown, $csrf);
            self::assertSame($knownMessage, $unknownMessage);
            self::assertStringContainsString(
                'Si el correo corresponde a una cuenta activa',
                $unknownMessage,
            );
            self::assertStringNotContainsString($known->email(), $unknownMessage);
            self::assertStringNotContainsString($unknown, $unknownMessage);

            // Five accepted requests exhaust the per-IP/account window; the
            // sixth must not reissue a token even though HTTP stays generic.
            $client->setServerParameters(['REMOTE_ADDR' => '198.51.100.51']);
            for ($attempt = 2; $attempt <= 5; ++$attempt) {
                self::assertSame(
                    $knownMessage,
                    self::requestRecovery($client, $known->email(), $csrf),
                );
            }
            $manager->refresh($resetKnown);
            $hashAtLimit = $resetKnown->tokenHash();
            self::assertSame(
                $knownMessage,
                self::requestRecovery($client, $known->email(), $csrf),
            );
            $manager->refresh($resetKnown);
            self::assertSame(
                $hashAtLimit,
                $resetKnown->tokenHash(),
                'A throttled POST must not issue a new password reset token.',
            );

            // A validly formed but expired token must not rotate the password.
            $rawExpired = bin2hex(random_bytes(32));
            $resetExpired = new PasswordResetToken(
                $expiredUser,
                hash('sha256', $rawExpired),
                new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC')),
            );
            $manager->persist($resetExpired);
            $manager->flush();
            $oldPasswordHash = $expiredUser->getPassword();

            $client->setServerParameters(['REMOTE_ADDR' => '198.51.100.53']);
            $resetPage = $client->request('GET', '/admin/restablecer-contrasena');
            self::assertResponseStatusCodeSame(200);
            $client->request('POST', '/admin/restablecer-contrasena', [
                '_csrf_token' => self::csrf($resetPage),
                'token' => $rawExpired,
                'password' => 'New-strong-password-456!',
                'password_confirmation' => 'New-strong-password-456!',
            ]);
            self::assertResponseStatusCodeSame(200);
            self::assertSelectorTextContains(
                '.alert-error',
                'No pudimos completar la recuperación.',
            );
            self::assertSame($oldPasswordHash, $expiredUser->getPassword());
            $manager->refresh($resetExpired);
            self::assertNull($resetExpired->consumedAt());
            self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
            self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
        } finally {
            if ($resetKnown instanceof PasswordResetToken) {
                $manager->remove($resetKnown);
            }
            if ($resetExpired instanceof PasswordResetToken) {
                $manager->remove($resetExpired);
            }
            $manager->flush();
            foreach ([$memberA, $memberB, $known, $expiredUser, $tenantA, $tenantB] as $entity) {
                $manager->remove($entity);
            }
            $manager->flush();
        }
    }

    private static function requestRecovery(
        \Symfony\Bundle\FrameworkBundle\KernelBrowser $client,
        string $email,
        string $csrf,
    ): string {
        $crawler = $client->request('POST', '/admin/recuperar-contrasena', [
            'email' => $email,
            '_csrf_token' => $csrf,
        ]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertSame(1, $crawler->filter('output.alert')->count());
        return trim($crawler->filter('output.alert')->text());
    }

    private static function csrf(Crawler $crawler): string
    {
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        self::assertIsString($token);
        self::assertNotSame('', $token);
        return $token;
    }
}
'''


class ContrasenaLimitesHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        controller = CONTROLLER.read_text(encoding="utf-8")
        limits = RATE_LIMITS.read_text(encoding="utf-8")
        self.assertIn("password_reset_request_ip:", limits)
        self.assertIn("password_reset_request_account:", limits)
        self.assertIn("password_reset_consume_token:", limits)
        self.assertIn("private const GENERIC_RESET_MESSAGE", controller)
        self.assertIn("->consume()", controller)
        self.assertIn("self::padResetResponse($startedAt);", controller)
        self.assertIn("catch (DomainException)", controller)

        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI is required: no static-only fallback")
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.exists(), "Installed PHPUnit required for real HTTP tests")
        with tempfile.TemporaryDirectory(prefix="condor-password-http-") as dirname:
            fixture = Path(dirname) / "PasswordHttpLimitsSmokeTest.php"
            fixture.write_text(PHP_HTTP_NEGATIVES, encoding="utf-8")
            syntax = subprocess.run(
                [php, "-l", str(fixture)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=30,
            )
            self.assertEqual(0, syntax.returncode, syntax.stdout + syntax.stderr)
            result = subprocess.run(
                [str(runner), str(fixture)],
                cwd=ROOT, capture_output=True, text=True, check=False, timeout=180,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
