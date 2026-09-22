<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Identity\PlatformOwnerTenantContext;
use App\Application\Identity\PlatformTenantManager;
use App\Application\Onboarding\CreateTenant;
use App\Application\Onboarding\CreateTenantInput;
use App\Application\Onboarding\ProvisionTenantInput;
use App\Domain\Identity\Entity\User;
use App\Domain\Observability\Entity\FunctionalSignal;
use App\Infrastructure\Observability\FunctionalSignalRecorder;
use App\Infrastructure\Security\AuthenticationSignalSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

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

    public function testLoginSignalsAreRecordedWithoutPiiThroughRealDatabaseWrite(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $recorder = static::getContainer()->get(FunctionalSignalRecorder::class);
        self::assertInstanceOf(FunctionalSignalRecorder::class, $recorder);
        $subscriber = static::getContainer()->get(
            AuthenticationSignalSubscriber::class,
        );
        self::assertInstanceOf(
            AuthenticationSignalSubscriber::class,
            $subscriber,
        );

        $before = $this->currentSignals();

        $subscriber->onLoginSuccess(
            $this->createStub(LoginSuccessEvent::class),
        );
        $subscriber->onLoginFailure(
            $this->createStub(LoginFailureEvent::class),
        );

        $after = $this->currentSignals();

        self::assertSame(
            1,
            $after['login_success'] - $before['login_success'],
        );
        self::assertSame(
            1,
            $after['login_failure'] - $before['login_failure'],
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

    public function testTenantCreationSignalsCoverBothRealCreationFlows(): void
    {
        static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $createTenant = static::getContainer()->get(CreateTenant::class);
        self::assertInstanceOf(CreateTenant::class, $createTenant);
        $platformTenants = static::getContainer()->get(PlatformTenantManager::class);
        self::assertInstanceOf(PlatformTenantManager::class, $platformTenants);

        $before = $this->currentSignals();
        $suffix = strtolower(bin2hex(random_bytes(4)));

        $onboarding = $createTenant->execute(new CreateTenantInput(
            'Empresa onboarding '.$suffix,
            'empresa-onboarding-'.$suffix,
            'Empresa onboarding '.$suffix.' SAS',
            null,
            'Principal',
            'owner-'.$suffix.'@example.test',
            'Owner onboarding',
            'CorrectHorseBattery1!',
        ));

        $actor = new User(
            'platform-'.$suffix.'@example.test',
            'Propietario plataforma',
            [User::ROLE_PLATFORM_OWNER],
        );
        $entityManager->persist($actor);
        $entityManager->flush();

        $platform = $platformTenants->createAndInviteOwner(
            $actor,
            new ProvisionTenantInput(
                'Empresa plataforma '.$suffix,
                'empresa-plataforma-'.$suffix,
                'Empresa plataforma '.$suffix.' SAS',
                null,
                'Principal',
            ),
            'invited-'.$suffix.'@example.test',
            'Admin invitado',
        );

        $after = $this->currentSignals();

        self::assertSame(
            2,
            $after['tenant_created'] - $before['tenant_created'],
        );

        $signals = $entityManager
            ->getRepository(FunctionalSignal::class)
            ->findBy(
                ['type' => FunctionalSignal::TENANT_CREATED],
                ['createdAt' => 'DESC'],
                2,
            );

        self::assertCount(2, $signals);
        $tenantIds = array_map(
            static fn (FunctionalSignal $signal): ?string => $signal->tenantId(),
            $signals,
        );
        self::assertContains($onboarding->tenantId, $tenantIds);
        self::assertContains($platform->provisioning->tenant->id(), $tenantIds);

        $sources = array_map(
            static fn (FunctionalSignal $signal): mixed => (
                $signal->context()['source'] ?? null
            ),
            $signals,
        );
        self::assertContains('onboarding', $sources);
        self::assertContains('platform_owner', $sources);
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
