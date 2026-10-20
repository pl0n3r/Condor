#!/usr/bin/env python3
"""Condor #775: HTTP acceptance for scoped, expiring diagnostic-share links.

Runs Symfony WebTestCase against the test database with two synthetic tenants.
No production credentials, external calls, customer records, or live writes.
"""
from __future__ import annotations

import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/SharedDiagnosticController.php"
SHARES = ROOT / "src/Application/Observability/DiagnosticShareService.php"
SANITIZER = ROOT / "src/Infrastructure/Observability/ErrorSanitizer.php"
RUNNER = ROOT / "vendor/bin/simple-phpunit"

PHP_FIXTURE = r'''<?php
declare(strict_types=1);

use App\Domain\Observability\Entity\DiagnosticShare;
use App\Domain\Observability\Entity\ErrorIncident;
use App\Domain\Organization\Entity\Tenant;
use App\Infrastructure\Observability\ErrorSanitizer;
use App\Shared\Id\UlidFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SharedDiagnosticHttpGeneratedTest extends WebTestCase
{
    public function testBearerLinksAreScopedAndFailClosed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $suffix = bin2hex(random_bytes(6));
        $tenantA = new Tenant('Diagnóstico A '.$suffix, 'diagnostic-a-'.$suffix);
        $tenantB = new Tenant('Diagnóstico B '.$suffix, 'diagnostic-b-'.$suffix);
        $tokenA = bin2hex(random_bytes(32));
        $tokenB = bin2hex(random_bytes(32));
        $expiredToken = bin2hex(random_bytes(32));
        $revokedToken = bin2hex(random_bytes(32));
        $secret = 'SYNTHETIC_SECRET_'.$suffix;
        $otherTenantMarker = 'TENANT_B_PRIVATE_'.$suffix;
        $sanitizer = new ErrorSanitizer(
            (string) static::getContainer()->getParameter('kernel.project_dir')
        );
        $error = new \RuntimeException(
            'Fallo controlado. Authorization: Bearer '.$secret
            .' token='.$secret.' user=usuario@example.test'
        );
        $safeMessage = $sanitizer->message($error);
        self::assertStringNotContainsString($secret, $safeMessage);
        self::assertStringNotContainsString('usuario@example.test', $safeMessage);

        $incidentA = new ErrorIncident(
            UlidFactory::new(), 500, 'GET', 'diagnostic_a_'.$tenantA->id(),
            \RuntimeException::class, $safeMessage, hash('sha256', 'a-'.$suffix),
            '0.1.194', str_repeat('a', 40),
            [['file' => 'src/Http/Controller/ExampleController.php',
              'line' => 18, 'call' => 'synthetic']],
        );
        $incidentB = new ErrorIncident(
            UlidFactory::new(), 503, 'POST', 'diagnostic_b_'.$tenantB->id(),
            \RuntimeException::class, $otherTenantMarker,
            hash('sha256', 'b-'.$suffix), '0.1.194', str_repeat('b', 40),
            [['file' => 'src/Http/Controller/ForeignController.php',
              'line' => 20, 'call' => 'foreign-synthetic']],
        );
        $shareA = new DiagnosticShare(
            $incidentA, hash('sha256', $tokenA), new \DateTimeImmutable('+20 minutes')
        );
        $shareB = new DiagnosticShare(
            $incidentB, hash('sha256', $tokenB), new \DateTimeImmutable('+20 minutes')
        );
        $expired = new DiagnosticShare(
            $incidentA, hash('sha256', $expiredToken), new \DateTimeImmutable('-10 minutes')
        );
        $revoked = new DiagnosticShare(
            $incidentB, hash('sha256', $revokedToken), new \DateTimeImmutable('+20 minutes')
        );
        $revoked->revoke();

        foreach ([
            $tenantA, $tenantB, $incidentA, $incidentB,
            $shareA, $shareB, $expired, $revoked,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $path = '/support/diagnostics/';
        // Anyone holding A's exact bearer token can read only A's sanitized
        // incident; neither B's error/trace nor either raw token may leak.
        $client->request('GET', $path.$tokenA);
        self::assertResponseStatusCodeSame(200);
        $response = $client->getResponse();
        self::assertStringContainsString(
            'no-store', (string) $response->headers->get('Cache-Control')
        );
        self::assertStringContainsString(
            'noindex', (string) $response->headers->get('X-Robots-Tag')
        );
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $body = (string) $response->getContent();
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($incidentA->id(), $data['diagnostic']['error_id']);
        self::assertSame($incidentA->requestId(), $data['diagnostic']['request_id']);
        self::assertSame($safeMessage, $data['diagnostic']['message']);
        self::assertSame(500, $data['diagnostic']['http_status']);
        self::assertSame('GET', $data['diagnostic']['method']);
        self::assertSame('diagnostic_a_'.$tenantA->id(), $data['diagnostic']['route']);
        self::assertSame('0.1.194', $data['diagnostic']['release']['version']);
        self::assertSame(str_repeat('a', 40), $data['diagnostic']['release']['sha']);
        self::assertTrue($data['share']['read_only']);
        self::assertTrue($data['share']['sanitized']);
        foreach ([
            $otherTenantMarker, $incidentB->id(), $incidentB->requestId(),
            $tenantB->id(), $tenantB->name(),
            $secret, 'usuario@example.test', $tokenA, $tokenB,
            hash('sha256', $tokenA), hash('sha256', $tokenB),
            'ForeignController.php', 'foreign-synthetic',
        ] as $private) {
            self::assertStringNotContainsString($private, $body);
        }

        // A different valid token selects only B's incident, never A's.
        $client->request('GET', $path.$tokenB);
        self::assertResponseStatusCodeSame(200);
        $bodyB = (string) $client->getResponse()->getContent();
        $dataB = json_decode($bodyB, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($incidentB->id(), $dataB['diagnostic']['error_id']);
        self::assertStringContainsString($otherTenantMarker, $bodyB);
        self::assertStringNotContainsString($incidentA->id(), $bodyB);
        self::assertStringNotContainsString($tenantA->id(), $bodyB);
        self::assertStringNotContainsString($tokenB, $bodyB);

        // Unknown, tampered, expired and revoked well-formed bearer tokens
        // must all return the same generic 404, without incident existence leaks.
        $tamperedToken = ($tokenA[0] === 'a' ? 'b' : 'a').substr($tokenA, 1);
        $notIssued = hash('sha256', 'unknown-'.$suffix);
        $invalidBodies = [];
        foreach ([$tamperedToken, $notIssued, $expiredToken, $revokedToken] as $invalid) {
            $client->request('GET', $path.$invalid);
            self::assertResponseStatusCodeSame(404);
            $denied = $client->getResponse();
            self::assertStringContainsString(
                'no-store', (string) $denied->headers->get('Cache-Control')
            );
            self::assertStringContainsString(
                'noindex', (string) $denied->headers->get('X-Robots-Tag')
            );
            $deniedBody = (string) $denied->getContent();
            $json = json_decode($deniedBody, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['error' => 'Diagnóstico no disponible.'], $json);
            foreach ([$otherTenantMarker, $secret, $incidentA->id(), $incidentB->id()] as $private) {
                self::assertStringNotContainsString($private, $deniedBody);
            }
            $invalidBodies[] = $deniedBody;
        }
        self::assertCount(1, array_unique($invalidBodies));

        foreach (['not-a-token', strtoupper($tokenA), 'abc', str_repeat('f', 65)] as $bad) {
            $client->request('GET', $path.$bad);
            self::assertResponseStatusCodeSame(404);
            $invalidBody = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($otherTenantMarker, $invalidBody);
            self::assertStringNotContainsString($secret, $invalidBody);
        }
        // Shared links are read-only; POST must not resolve into a mutation.
        $client->request('POST', $path.$tokenA);
        self::assertResponseStatusCodeSame(405);
    }
}
'''


class DiagnosticoCompartidoHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI required for real HTTP acceptance")
        self.assertTrue(RUNNER.is_file(), "Symfony PHPUnit test runner required")
        for source in (CONTROLLER, SHARES, SANITIZER):
            self.assertTrue(source.is_file(), str(source))
            checked = subprocess.run(
                [php, "-l", str(source)], cwd=ROOT,
                capture_output=True, text=True, timeout=25, check=False,
            )
            self.assertEqual(0, checked.returncode, checked.stdout + checked.stderr)

        with tempfile.TemporaryDirectory(prefix="condor-shared-diagnostic-http-") as temp:
            fixture = Path(temp) / "SharedDiagnosticHttpGeneratedTest.php"
            fixture.write_text(PHP_FIXTURE, encoding="utf-8")
            lint = subprocess.run(
                [php, "-l", str(fixture)], cwd=ROOT,
                capture_output=True, text=True, timeout=25, check=False,
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            run = subprocess.run(
                [str(RUNNER), "--filter", "testBearerLinksAreScopedAndFailClosed", str(fixture)],
                cwd=ROOT, capture_output=True, text=True, timeout=180, check=False,
            )
            self.assertEqual(0, run.returncode, run.stdout + run.stderr)


if __name__ == "__main__":
    unittest.main()
