<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Identity\Entity\User;
use App\Domain\Observability\Entity\ErrorIncident;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class PlatformDiagnosticsControllerTest extends WebTestCase
{
    public function testOwnerCreatesSanitizedTemporaryDiagnosticShare(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $csrf = $container->get(CsrfTokenManagerInterface::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $csrf);

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
        $token = $csrf->getToken('diagnostic_share_'.$incident->id())->getValue();
        $crawler = $client->request(
            'POST',
            '/adminpl0n3r/diagnosticos/'.$incident->id().'/compartir',
            ['_token' => $token],
        );

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
}
