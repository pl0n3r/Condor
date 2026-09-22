<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Identity\Entity\User;
use App\Domain\Observability\Entity\DiagnosticShare;
use App\Domain\Observability\Entity\ErrorIncident;
use App\Infrastructure\Observability\FatalLog;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformDiagnosticsControllerTest extends WebTestCase
{
    public function testOwnerCreatesSanitizedTemporaryDiagnosticShare(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $owner = new User(
            'diagnostic-owner-'.bin2hex(random_bytes(4)).'@example.test',
            'Propietario diagnóstico',
            [User::ROLE_PLATFORM_OWNER],
        );
        $incident = new ErrorIncident(
            requestId: '01KTESTREQUESTID0000000000',
            status: 500,
            method: 'GET',
            routeName: 'app_platform_owner',
            exceptionClass: 'RuntimeException',
            message: 'mensaje [REDACTED] [EMAIL]',
            fingerprint: str_repeat('a', 64),
            version: '0.1.4',
            releaseSha: str_repeat('b', 40),
            trace: [[
                'file' => 'src/Http/Controller/PlatformOwnerController.php',
                'line' => 25,
                'call' => '{throw}',
            ]],
        );
        $entityManager->persist($owner);
        $entityManager->persist($incident);
        $entityManager->flush();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/adminpl0n3r/diagnosticos');
        self::assertResponseIsSuccessful();

        $form = $crawler
            ->filter(
                'form[action="/adminpl0n3r/diagnosticos/'.
                $incident->id().
                '/compartir"]'
            )
            ->form();
        $crawler = $client->submit($form);

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('code')->text();
        self::assertMatchesRegularExpression(
            '#/support/diagnostics/[a-f0-9]{64}$#',
            $text,
        );

        $path = (string) parse_url($text, PHP_URL_PATH);
        $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'no-store',
            (string) $client->getResponse()->headers->get('Cache-Control'),
        );
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame($incident->id(), $payload['diagnostic']['error_id']);
        self::assertTrue($payload['share']['read_only']);
        self::assertTrue($payload['share']['sanitized']);
        self::assertArrayNotHasKey('headers', $payload['diagnostic']);
        self::assertArrayNotHasKey('cookies', $payload['diagnostic']);
        self::assertArrayNotHasKey('request_body', $payload['diagnostic']);
    }

    public function testNormalAdministratorCannotOpenOwnerDiagnostics(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = new User(
            'admin-'.bin2hex(random_bytes(4)).'@example.test',
            'Administrador',
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);
        $client->request('GET', '/adminpl0n3r/diagnosticos');

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnknownShareTokenReturnsGeneric404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/support/diagnostics/'.str_repeat('a', 64));

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(
            'token',
            strtolower((string) $client->getResponse()->getContent()),
        );
    }

    public function testRevokedAndExpiredSharesAreIndistinguishableFromUnknownToken(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $incident = new ErrorIncident(
            requestId: '01KTESTREQUESTID0000000001',
            status: 500,
            method: 'GET',
            routeName: 'app_platform_owner',
            exceptionClass: 'RuntimeException',
            message: 'mensaje sanitizado',
            fingerprint: str_repeat('c', 64),
            version: '0.1.5',
            releaseSha: str_repeat('d', 40),
            trace: [],
        );

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $revokedToken = bin2hex(random_bytes(32));
        $revoked = new DiagnosticShare(
            $incident,
            hash('sha256', $revokedToken),
            $now->add(new DateInterval('PT30M')),
        );
        $revoked->revoke();

        $expiredToken = bin2hex(random_bytes(32));
        $expired = new DiagnosticShare(
            $incident,
            hash('sha256', $expiredToken),
            $now->sub(new DateInterval('PT1M')),
        );

        $entityManager->persist($incident);
        $entityManager->persist($revoked);
        $entityManager->persist($expired);
        $entityManager->flush();

        $client->request('GET', '/support/diagnostics/'.str_repeat('f', 64));
        self::assertResponseStatusCodeSame(404);
        $genericBody = (string) $client->getResponse()->getContent();

        foreach ([$revokedToken, $expiredToken] as $token) {
            $client->request('GET', '/support/diagnostics/'.$token);

            self::assertResponseStatusCodeSame(404);
            self::assertSame($genericBody, (string) $client->getResponse()->getContent());
            self::assertStringContainsString(
                'no-store',
                (string) $client->getResponse()->headers->get('Cache-Control'),
            );
        }
    }

    public function testDiagnosticsPageExposesTheStandaloneFatalLogLink(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $appSecret = static::getContainer()->getParameter('kernel.secret');
        self::assertIsString($appSecret);

        $owner = new User(
            'diagnostic-fatal-log-'.bin2hex(random_bytes(4)).'@example.test',
            'Propietario Log Fatal',
            [User::ROLE_PLATFORM_OWNER],
        );
        $entityManager->persist($owner);
        $entityManager->flush();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/adminpl0n3r/diagnosticos');
        self::assertResponseIsSuccessful();

        $expectedToken = FatalLog::accessToken($appSecret);
        $text = $crawler->filter('.workspace')->text();

        self::assertStringContainsString('platform-fatal-log.php', $text);
        self::assertStringContainsString($expectedToken, $text);
    }
}
