<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Identity\PlatformOwnerTenantContext;
use App\Domain\Identity\Entity\User;
use App\Domain\Observability\Entity\FunctionalSignal;
use App\Infrastructure\Observability\FunctionalSignalRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PlatformOwnerFunctionalSignalsTest extends WebTestCase
{
    public function testSignalsShapeHasNoPlaceholderOrMissingKeys(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $owner = new User(
            'owner-shape-'.bin2hex(random_bytes(4)).'@example.test',
            'Propietario Forma',
            [User::ROLE_PLATFORM_OWNER],
        );
        $entityManager->persist($owner);
        $entityManager->flush();

        $client->loginUser($owner);
        $client->request('GET', '/adminpl0n3r/api/context');

        self::assertResponseIsSuccessful();
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            [
                'tenant_created',
                'login_success',
                'login_failure',
                'authorization_denied',
                'role_modified',
            ],
            array_keys($payload['signals_last_30_days']),
        );
        foreach ($payload['signals_last_30_days'] as $count) {
            self::assertIsInt($count);
            self::assertGreaterThanOrEqual(0, $count);
        }
    }

    public function testRecordedSignalsAreAggregatedForTheOwner(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $recorder = static::getContainer()->get(FunctionalSignalRecorder::class);
        self::assertInstanceOf(FunctionalSignalRecorder::class, $recorder);

        $owner = new User(
            'owner-signals-'.bin2hex(random_bytes(4)).'@example.test',
            'Propietario Con Señales',
            [User::ROLE_PLATFORM_OWNER],
        );
        $entityManager->persist($owner);
        $entityManager->flush();

        $client->loginUser($owner);
        $client->request('GET', '/adminpl0n3r/api/context');
        $before = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        )['signals_last_30_days'];

        $recorder->record(FunctionalSignal::TENANT_CREATED, 'irrelevante');
        $recorder->record(FunctionalSignal::ROLE_MODIFIED, 'irrelevante');
        $recorder->record(FunctionalSignal::ROLE_MODIFIED, 'irrelevante');

        $client->request('GET', '/adminpl0n3r/api/context');
        self::assertResponseIsSuccessful();
        $after = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        )['signals_last_30_days'];

        self::assertSame(
            1,
            $after['tenant_created'] - $before['tenant_created'],
        );
        self::assertSame(
            2,
            $after['role_modified'] - $before['role_modified'],
        );
    }

    public function testTenantUserCannotSeeGlobalSignals(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $tenantUser = new User(
            'tenant-signals-'.bin2hex(random_bytes(4)).'@example.test',
            'Usuario Tenant',
        );
        $entityManager->persist($tenantUser);
        $entityManager->flush();

        $client->loginUser($tenantUser);
        $client->request('GET', '/adminpl0n3r/api/context');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRealLoginAttemptsRecordSuccessAndFailureSignals(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $passwordHasher = static::getContainer()->get(
            UserPasswordHasherInterface::class,
        );
        self::assertInstanceOf(
            UserPasswordHasherInterface::class,
            $passwordHasher,
        );

        $email = 'login-signal-'.bin2hex(random_bytes(4)).'@example.test';
        $user = new User($email, 'Usuario Login');
        $user->setPasswordHash(
            $passwordHasher->hashPassword($user, 'clave-valida-123'),
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $before = $this->currentSignals();

        $client->request('GET', '/admin/login');
        $client->submitForm('Ingresar', [
            '_username' => $email,
            '_password' => 'clave-incorrecta',
        ]);
        self::assertResponseRedirects('/admin/login');

        $client->request('GET', '/admin/login');
        $client->submitForm('Ingresar', [
            '_username' => $email,
            '_password' => 'clave-valida-123',
        ]);
        self::assertResponseRedirects();

        $after = $this->currentSignals();

        self::assertGreaterThanOrEqual(
            $before['login_success'] + 1,
            $after['login_success'],
        );
        self::assertGreaterThanOrEqual(
            $before['login_failure'] + 1,
            $after['login_failure'],
        );

        $failureSignals = $entityManager
            ->getRepository(FunctionalSignal::class)
            ->findBy(['type' => FunctionalSignal::LOGIN_FAILURE]);
        foreach ($failureSignals as $signal) {
            self::assertInstanceOf(FunctionalSignal::class, $signal);
            self::assertSame([], $signal->context());
            self::assertNull($signal->tenantId());
        }
    }

    public function testAuthorizationDenialRecordsSignalWithoutRequestDetails(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $before = $this->currentSignals();

        $tenantUser = new User(
            'tenant-denied-'.bin2hex(random_bytes(4)).'@example.test',
            'Usuario Sin Permiso',
        );
        $entityManager->persist($tenantUser);
        $entityManager->flush();

        $client->loginUser($tenantUser);
        $client->request('GET', '/adminpl0n3r/api/context');
        self::assertResponseStatusCodeSame(403);

        $after = $this->currentSignals();

        self::assertGreaterThanOrEqual(
            $before['authorization_denied'] + 1,
            $after['authorization_denied'],
        );
    }

    /**
     * Lee la agregación directamente del servicio de aplicación en vez
     * de abrir un segundo cliente HTTP: WebTestCase solo admite un
     * arranque de kernel por test, y esta sonda se usa antes/después
     * de un flujo (login real, 403) que ya ocupa el único cliente.
     *
     * @return array<string, int>
     */
    private function currentSignals(): array
    {
        $context = static::getContainer()->get(
            PlatformOwnerTenantContext::class,
        );
        self::assertInstanceOf(PlatformOwnerTenantContext::class, $context);

        return $context->functionalSignals();
    }
}
