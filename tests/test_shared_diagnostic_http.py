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



# The lightweight contract job intentionally does not install Composer/vendor.
# This no-vendor fixture executes the REAL controller, share service, incident
# entities and sanitizer. Only framework transport/ORM storage are test doubles.
# When vendor is installed, the same acceptance additionally runs Symfony's
# real WebTestCase and Doctrine-backed HTTP paths above.
PHP_OFFLINE = r'''<?php
declare(strict_types=1);

namespace Symfony\Component\Routing\Attribute {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    final class Route {
        public function __construct(...$arguments) {}
    }
}
namespace Symfony\Component\HttpFoundation {
    final class JsonResponse {
        public function __construct(
            public array $data, public int $status = 200, public array $headers = [],
        ) {}
        public function getContent(): string {
            return json_encode($this->data, JSON_THROW_ON_ERROR);
        }
    }
}
namespace Doctrine\ORM {
    interface EntityManagerInterface {
        public function getRepository(string $entityName): object;
        public function persist(object $entity): void;
        public function flush(): void;
    }
}
namespace App\Shared\Id {
    final class UlidFactory {
        private static int $count = 0;
        public static function new(): string {
            return str_pad((string) (++self::$count), 26, '0', STR_PAD_LEFT);
        }
    }
}
namespace SharedDiagnosticAcceptance {
    use App\Application\Observability\DiagnosticShareService;
    use App\Domain\Observability\Entity\DiagnosticShare;
    use App\Domain\Observability\Entity\ErrorIncident;
    use App\Domain\Organization\Entity\Tenant;
    use App\Http\Controller\SharedDiagnosticController;
    use App\Infrastructure\Observability\ErrorSanitizer;
    use Doctrine\ORM\EntityManagerInterface;

    foreach (array_slice($argv, 1) as $file) {
        require_once $file;
    }
    function check(bool $ok, string $message): void {
        if (!$ok) {
            throw new \RuntimeException($message);
        }
    }

    $tenantA = new Tenant('Synthetic A', 'diagnostic-a');
    $tenantB = new Tenant('Synthetic B', 'diagnostic-b');
    $secret = 'SYNTHETIC_TOKEN_NEVER_LEAK';
    $foreignPrivate = 'TENANT_B_PRIVATE_NOT_FOR_A';
    $tokenA = str_repeat('a', 64);
    $tokenB = str_repeat('b', 64);
    $tokenExpired = str_repeat('c', 64);
    $tokenRevoked = str_repeat('d', 64);
    $sanitizer = new ErrorSanitizer('/tmp/condor-fixture');
    $safe = $sanitizer->message(
        new \RuntimeException('Failure. Bearer '.$secret.' token='.$secret)
    );
    check(!str_contains($safe, $secret), 'Sanitizer did not redact synthetic secret');

    $incidentA = new ErrorIncident(
        'request-a', 500, 'GET', 'tenant_'.$tenantA->id(),
        \RuntimeException::class, $safe, hash('sha256', 'a'), '0.1.194',
        str_repeat('a', 40), [['file' => 'src/Controller/A.php', 'line' => 7, 'call' => 'safe']],
    );
    $incidentB = new ErrorIncident(
        'request-b', 503, 'GET', 'tenant_'.$tenantB->id(),
        \RuntimeException::class, $foreignPrivate, hash('sha256', 'b'), '0.1.194',
        str_repeat('b', 40), [['file' => 'src/Controller/B.php', 'line' => 8, 'call' => 'private']],
    );
    $rows = [
        new DiagnosticShare($incidentA, hash('sha256', $tokenA), new \DateTimeImmutable('+20 minutes')),
        new DiagnosticShare($incidentB, hash('sha256', $tokenB), new \DateTimeImmutable('+20 minutes')),
        new DiagnosticShare($incidentA, hash('sha256', $tokenExpired), new \DateTimeImmutable('-10 minutes')),
        new DiagnosticShare($incidentB, hash('sha256', $tokenRevoked), new \DateTimeImmutable('+20 minutes')),
    ];
    $rows[3]->revoke();
    $repository = new class($rows) {
        public function __construct(private array $rows) {}
        public function findOneBy(array $criteria): ?DiagnosticShare {
            foreach ($this->rows as $row) {
                if ($row->tokenHash() === ($criteria['tokenHash'] ?? null)) {
                    return $row;
                }
            }
            return null;
        }
    };
    $manager = new class($repository) implements EntityManagerInterface {
        public function __construct(private object $repository) {}
        public function getRepository(string $entityName): object {
            check($entityName === DiagnosticShare::class, 'Unexpected repository');
            return $this->repository;
        }
        public function persist(object $entity): void {
            throw new \RuntimeException('Read-only test attempted to persist');
        }
        public function flush(): void {
            throw new \RuntimeException('Read-only test attempted to flush');
        }
    };
    $shares = new DiagnosticShareService($manager);
    $controller = new SharedDiagnosticController();

    $a = $controller($tokenA, $shares);
    check($a->status === 200, 'Valid A should return HTTP 200');
    $json = json_decode($a->getContent(), true, 512, JSON_THROW_ON_ERROR);
    check($json['diagnostic']['error_id'] === $incidentA->id(), 'A incident mismatched');
    check($json['diagnostic']['message'] === $safe, 'A not sanitized');
    check($json['share']['read_only'] === true, 'Not read only');
    check($json['share']['sanitized'] === true, 'Sanitized claim absent');
    check(str_contains($a->headers['Cache-Control'], 'no-store'), 'A cacheable');
    check(str_contains($a->headers['X-Robots-Tag'], 'noindex'), 'A indexed');
    check($a->headers['Referrer-Policy'] === 'no-referrer', 'Referrer leak');
    foreach ([$foreignPrivate, $incidentB->id(), $tenantB->id(),
              $tokenA, $tokenB, hash('sha256', $tokenA), $secret] as $private) {
        check(!str_contains($a->getContent(), $private), 'A leaked private content');
    }

    $b = $controller($tokenB, $shares);
    check($b->status === 200, 'Valid B should return HTTP 200');
    check(str_contains($b->getContent(), $foreignPrivate), 'Wrong B incident');
    check(!str_contains($b->getContent(), $incidentA->id()), 'B leaked A');
    check(!str_contains($b->getContent(), $tokenB), 'B exposed bearer token');

    $denied = [];
    $tampered = 'f'.substr($tokenA, 1);
    foreach ([$tampered, str_repeat('e', 64), $tokenExpired, $tokenRevoked,
              'not-a-token', strtoupper($tokenA)] as $invalid) {
        $result = $controller($invalid, $shares);
        check($result->status === 404, 'Denied token did not return 404');
        check($result->data === ['error' => 'Diagnóstico no disponible.'], 'Non-generic 404');
        check(str_contains($result->headers['Cache-Control'], 'no-store'), 'Denied response cacheable');
        check(!str_contains($result->getContent(), $foreignPrivate), 'Denied response leaked B');
        check(!str_contains($result->getContent(), $secret), 'Denied response leaked secret');
        $denied[] = $result->getContent();
    }
    check(count(array_unique($denied)) === 1, 'Invalid token enumeration');
    echo "PASS: real controller/service/sanitizer, two synthetic tenants, negative tokens\n";
}
'''


class DiagnosticoCompartidoHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI required for controller acceptance")
        for source in (CONTROLLER, SHARES, SANITIZER):
            self.assertTrue(source.is_file(), str(source))
            checked = subprocess.run(
                [php, "-l", str(source)], cwd=ROOT,
                capture_output=True, text=True, timeout=25, check=False,
            )
            self.assertEqual(0, checked.returncode, checked.stdout + checked.stderr)

        with tempfile.TemporaryDirectory(prefix="condor-shared-diagnostic-http-") as temp:
            fixture = Path(temp) / "SharedDiagnosticControllerContract.php"
            fixture.write_text(PHP_OFFLINE, encoding="utf-8")
            lint = subprocess.run(
                [php, "-l", str(fixture)], cwd=ROOT,
                capture_output=True, text=True, timeout=25, check=False,
            )
            self.assertEqual(0, lint.returncode, lint.stdout + lint.stderr)
            source_files = [
                ROOT / "src/Domain/Organization/Entity/Tenant.php",
                ROOT / "src/Domain/Observability/Entity/ErrorIncident.php",
                ROOT / "src/Domain/Observability/Entity/DiagnosticShare.php",
                SANITIZER, SHARES, CONTROLLER,
            ]
            run = subprocess.run(
                [php, str(fixture), *(str(path) for path in source_files)],
                cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
            )
            self.assertEqual(0, run.returncode, run.stdout + run.stderr)
            self.assertIn("PASS: real controller/service/sanitizer", run.stdout)

            # Environments with Composer also run the actual Symfony HTTP
            # kernel and Doctrine test DB. The lightweight CI job has no
            # vendor directory, so never claim it executed that layer.
            if RUNNER.is_file():
                web = Path(temp) / "SharedDiagnosticHttpGeneratedTest.php"
                web.write_text(PHP_FIXTURE, encoding="utf-8")
                lint_web = subprocess.run(
                    [php, "-l", str(web)], cwd=ROOT,
                    capture_output=True, text=True, timeout=25, check=False,
                )
                self.assertEqual(
                    0, lint_web.returncode, lint_web.stdout + lint_web.stderr
                )
                tested = subprocess.run(
                    [str(RUNNER), "--filter", "testBearerLinksAreScopedAndFailClosed", str(web)],
                    cwd=ROOT, capture_output=True, text=True, check=False, timeout=180,
                )
                self.assertEqual(
                    0, tested.returncode, tested.stdout + tested.stderr
                )


if __name__ == "__main__":
    unittest.main()
