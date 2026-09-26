<?php

declare(strict_types=1);

namespace App\Tests\Application\Identity;

use App\Application\Identity\PasswordResetUrlFactory;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PasswordResetUrlFactoryTest extends KernelTestCase
{
    public function testItUsesOnlyConfiguredHttpsCanonicalOrigin(): void
    {
        $factory = new PasswordResetUrlFactory('https://secure.example.test');
        $token = str_repeat('a', 64);

        self::assertSame(
            'https://secure.example.test/admin/restablecer-contrasena#token='.$token,
            $factory->resetUrl($token),
        );
    }

    public function testContainerUsesConfiguredTestOrigin(): void
    {
        static::bootKernel();
        $factory = static::getContainer()->get(PasswordResetUrlFactory::class);
        self::assertSame(
            'https://condor.test/admin/restablecer-contrasena#token='.str_repeat('a', 64),
            $factory->resetUrl(str_repeat('a', 64)),
        );
    }

    public function testContainerFallsBackToProductionDeployOriginWithoutEnv(): void
    {
        $server = $_SERVER['CONDOR_CANONICAL_URL'] ?? null;
        $environment = $_ENV['CONDOR_CANONICAL_URL'] ?? null;
        $process = getenv('CONDOR_CANONICAL_URL');
        unset($_SERVER['CONDOR_CANONICAL_URL'], $_ENV['CONDOR_CANONICAL_URL']);
        putenv('CONDOR_CANONICAL_URL');
        try {
            static::bootKernel();
            $factory = static::getContainer()->get(PasswordResetUrlFactory::class);
            self::assertSame(
                'https://www.condorapp.com.co/admin/restablecer-contrasena#token='
                    .str_repeat('a', 64),
                $factory->resetUrl(str_repeat('a', 64)),
            );
        } finally {
            static::ensureKernelShutdown();
            if ($server !== null) {
                $_SERVER['CONDOR_CANONICAL_URL'] = $server;
            }
            if ($environment !== null) {
                $_ENV['CONDOR_CANONICAL_URL'] = $environment;
            }
            if ($process !== false) {
                putenv('CONDOR_CANONICAL_URL='.$process);
            }
        }
    }

    public function testItRejectsHttpCredentialsQueryAndInvalidToken(): void
    {
        foreach ([
            'http://example.test',
            'https://user:secret@example.test',
            'https://example.test?host=evil.test',
        ] as $invalid) {
            try {
                new PasswordResetUrlFactory($invalid);
                self::fail('Debe rechazar una URL canónica insegura.');
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }

        $factory = new PasswordResetUrlFactory('https://secure.example.test');
        $this->expectException(DomainException::class);
        $factory->resetUrl('invalid');
    }
}
