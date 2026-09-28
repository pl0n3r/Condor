<?php

declare(strict_types=1);

namespace App\Tests\Application\Identity;

use App\Application\Identity\PasswordResetUrlFactory;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PasswordResetUrlFactoryTest extends KernelTestCase
{
    public function testItUsesOnlyCleanHttpsOriginAndFragmentToken(): void
    {
        $factory = new PasswordResetUrlFactory('https://secure.example.test/');
        $token = str_repeat('a', 64);

        self::assertSame(
            'https://secure.example.test/admin/restablecer-contrasena#token='.$token,
            $factory->resetUrl($token),
        );
    }

    public function testContainerWiresServerSideCanonicalOrigin(): void
    {
        self::bootKernel();
        $factory = static::getContainer()->get(PasswordResetUrlFactory::class);
        self::assertInstanceOf(PasswordResetUrlFactory::class, $factory);

        $token = str_repeat('b', 64);
        self::assertMatchesRegularExpression(
            '#^https://[^/]+/admin/restablecer-contrasena\\#token='.$token.'$#',
            $factory->resetUrl($token),
        );
    }

    public function testItRejectsUnsafeOriginsAndInvalidTokens(): void
    {
        foreach ([
            'http://example.test',
            'https://user:secret@example.test',
            'https://example.test/path',
            'https://example.test?host=evil.test',
            'https://example.test/#fragment',
        ] as $invalidOrigin) {
            try {
                new PasswordResetUrlFactory($invalidOrigin);
                self::fail('Debe rechazar un origen canónico inseguro.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }

        $factory = new PasswordResetUrlFactory('https://secure.example.test');
        $this->expectException(DomainException::class);
        $factory->resetUrl('invalid');
    }
}
