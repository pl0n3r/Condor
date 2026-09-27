<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Commercial\CommercialCatalogSeeder;
use App\Domain\Observability\Entity\FunctionalSignal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlanConfiguratorTelemetryBackendTest extends WebTestCase
{
    private EntityManagerInterface $manager;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;

        (new CommercialCatalogSeeder($manager))->seed();
        $this->deleteTelemetrySignals();
    }

    protected function tearDown(): void
    {
        $this->deleteTelemetrySignals();
        parent::tearDown();
    }

    public function testFunnelTelemetryIsAllowlistedWithoutPii(): void
    {
        $this->client->jsonRequest(
            'POST',
            '/api/public/configurator/events',
            [
                'event' => 'completion',
                'plan' => 'business',
                'vertical' => 'commerce',
                'cycle' => 'monthly',
                'addon' => 'production-lite',
                'step' => 'summary',
            ],
            server: ['REMOTE_ADDR' => '198.51.100.41'],
        );

        self::assertResponseStatusCodeSame(202);

        $signals = $this->manager
            ->getRepository(FunctionalSignal::class)
            ->findBy(['type' => FunctionalSignal::CONFIGURATOR_FUNNEL]);

        self::assertCount(1, $signals);
        $signal = $signals[0];
        self::assertInstanceOf(FunctionalSignal::class, $signal);
        self::assertNull($signal->tenantId());
        self::assertSame([
            'event' => 'completion',
            'plan' => 'business',
            'vertical' => 'commerce',
            'cycle' => 'monthly',
            'addon' => 'production-lite',
            'step' => 'summary',
        ], $signal->context());

        $rejected = [
            [['event' => 'start', 'name' => 'Persona Real'], '198.51.100.42'],
            [['event' => 'start', 'session_id' => 'session-123'], '198.51.100.43'],
            [['event' => 'start', 'plan' => 'persona-real'], '198.51.100.44'],
            [['event' => 'start', 'vertical' => 'a12e4567-e89b-12d3-a456-426614174000'], '198.51.100.45'],
            [['event' => 'start', 'ip' => '198.51.100.46'], '198.51.100.46'],
        ];

        foreach ($rejected as [$payload, $ip]) {
            $this->client->jsonRequest(
                'POST',
                '/api/public/configurator/events',
                $payload,
                server: ['REMOTE_ADDR' => $ip],
            );
            self::assertResponseStatusCodeSame(422);
        }

        self::assertSame(
            1,
            $this->manager
                ->getRepository(FunctionalSignal::class)
                ->count(['type' => FunctionalSignal::CONFIGURATOR_FUNNEL]),
        );
    }

    public function testTelemetryRateLimitIsIndependentFromQuote(): void
    {
        $ip = '198.51.100.90';

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $this->client->jsonRequest(
                'POST',
                '/api/public/configurator/events',
                ['event' => 'start'],
                server: ['REMOTE_ADDR' => $ip],
            );
            self::assertResponseStatusCodeSame(202);
        }

        $this->client->jsonRequest(
            'POST',
            '/api/public/configurator/events',
            ['event' => 'start'],
            server: ['REMOTE_ADDR' => $ip],
        );
        self::assertResponseStatusCodeSame(429);

        $this->client->jsonRequest(
            'POST',
            '/api/public/configurator/quote',
            [
                'plan' => 'business',
                'vertical' => 'commerce',
                'cycle' => 'monthly',
                'quantities' => [
                    'users' => 12,
                    'locations' => 3,
                    'companies' => 1,
                ],
                'addons' => [],
            ],
            server: ['REMOTE_ADDR' => $ip],
        );
        self::assertResponseIsSuccessful();
    }

    private function deleteTelemetrySignals(): void
    {
        $this->manager->getConnection()->executeStatement(
            'DELETE FROM condor_functional_signal WHERE type = :type',
            ['type' => FunctionalSignal::CONFIGURATOR_FUNNEL],
        );
    }
}
