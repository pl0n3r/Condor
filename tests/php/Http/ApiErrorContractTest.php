<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiErrorContractTest extends WebTestCase
{
    public function testUnauthenticatedApiRequestReturnsJson401WithRequestId(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            '/api/v1/context',
            server: ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertResponseHeaderSame('Cache-Control', 'no-store');

        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('unauthenticated', $payload['error']);
        self::assertSame(401, $payload['status']);
        self::assertMatchesRegularExpression(
            '/^[0-9A-HJKMNP-TV-Z]{26}$/',
            $payload['request_id'],
        );
        self::assertSame(
            $payload['request_id'],
            $client->getResponse()->headers->get('X-Request-Id'),
        );
    }

    public function testUnknownApiRouteReturnsStableJson404ForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(
            EntityManagerInterface::class,
        );
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = new User(
            'api-contract-'.bin2hex(random_bytes(4)).'@example.test',
            'Contrato API',
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);
        $client->request(
            'GET',
            '/api/v1/recurso-inexistente',
            server: ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('not_found', $payload['error']);
        self::assertSame(
            'El recurso solicitado no existe.',
            $payload['message'],
        );
        self::assertSame(404, $payload['status']);
        self::assertSame(
            $payload['request_id'],
            $client->getResponse()->headers->get('X-Request-Id'),
        );
    }
}
