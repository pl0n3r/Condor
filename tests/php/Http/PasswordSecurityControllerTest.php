<?php

declare(strict_types=1);

namespace App\Tests\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PasswordSecurityControllerTest extends WebTestCase
{
    public function testAnonymousRecoveryPagesSendPrivacyHeaders(): void
    {
        $client = static::createClient();

        foreach ([
            '/admin/recuperar-contrasena',
            '/admin/restablecer-contrasena',
        ] as $path) {
            $client->request('GET', $path);

            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
            self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
            self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('private'));
        }
    }

    public function testResetRequestRejectsInvalidCsrfBeforeAnyRecovery(): void
    {
        $client = static::createClient();
        $client->request('POST', '/admin/recuperar-contrasena', [
            'email' => 'unknown@example.test',
            '_csrf_token' => 'invalid-csrf-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('private'));
    }

    public function testResetCompletionRejectsInvalidCsrf(): void
    {
        $client = static::createClient();
        $client->request('POST', '/admin/restablecer-contrasena', [
            'token' => str_repeat('a', 64),
            'password' => 'Valid-password-123!',
            'password_confirmation' => 'Valid-password-123!',
            '_csrf_token' => 'invalid-csrf-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('private'));
    }
}
