<?php
declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Commercial\CommercialCatalogSeeder;
use App\Domain\Commercial\Entity\Quote;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlanConfiguratorPublicApiTest extends WebTestCase
{
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;
        (new CommercialCatalogSeeder($manager))->seed();
    }

    public function testPublicCatalogAndOptionsUseCanonicalReadModel(): void
    {
        $client = static::createClient();
        $client->request('GET', '/configurar-condor');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/public/configurator/catalog');
        self::assertResponseIsSuccessful();
        $catalog = self::payload($client);
        self::assertContains('business', array_column($catalog['plans'], 'key'));
        self::assertContains('legal', array_column($catalog['verticals'], 'key'));

        $client->request(
            'GET',
            '/api/public/configurator/options?plan=business&vertical=legal',
        );
        self::assertResponseIsSuccessful();
        $options = self::payload($client);
        $capabilities = array_column($options['capabilities'], 'key');
        self::assertNotContains('inventory', $capabilities);
        self::assertNotContains('manufacturing', $capabilities);

        $addons = [];
        foreach ($options['addons'] as $addon) {
            $addons[$addon['key']] = $addon;
        }
        self::assertFalse($addons['extra-user']['selectable']);
        self::assertTrue($addons['production-lite']['selectable']);
    }

    public function testPreviewIsAuthoritativeAndDoesNotPersistQuote(): void
    {
        $before = $this->manager->getRepository(Quote::class)->count([]);
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/public/configurator/quote', [
            'plan' => 'business',
            'vertical' => 'commerce',
            'cycle' => 'monthly',
            'quantities' => ['users' => 12, 'locations' => 3, 'companies' => 1],
            'addons' => [],
            'total' => 1,
        ]);

        self::assertResponseIsSuccessful();
        $payload = self::payload($client);
        self::assertSame(229700, $payload['quote']['total_amount']);
        self::assertSame($before, $this->manager->getRepository(Quote::class)->count([]));
    }

    public function testIncompatibleAndManualScaleAddonsFailClosed(): void
    {
        $client = static::createClient();
        $base = [
            'plan' => 'pro',
            'vertical' => 'legal',
            'cycle' => 'monthly',
            'quantities' => ['users' => 30, 'locations' => 10, 'companies' => 5],
        ];

        $client->jsonRequest('POST', '/api/public/configurator/quote', $base + [
            'addons' => ['production-lite'],
        ]);
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('POST', '/api/public/configurator/quote', [
            'plan' => 'business',
            'vertical' => 'commerce',
            'cycle' => 'monthly',
            'quantities' => ['users' => 10, 'locations' => 3, 'companies' => 1],
            'addons' => ['extra-user'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testPreviewIsRateLimitedAndPayloadAllowlisted(): void
    {
        $client = static::createClient();
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $client->jsonRequest(
                'POST',
                '/api/public/configurator/quote',
                ['shadow_price' => 1],
                server: ['REMOTE_ADDR' => '198.51.100.77'],
            );
            self::assertResponseStatusCodeSame(422);
        }
        $client->jsonRequest(
            'POST',
            '/api/public/configurator/quote',
            ['shadow_price' => 1],
            server: ['REMOTE_ADDR' => '198.51.100.77'],
        );
        self::assertResponseStatusCodeSame(429);
    }

    public function testLegalAndProposalStatesComeFromCanonicalServices(): void
    {
        $client = static::createClient();
        $client->request(
            'GET',
            '/api/public/configurator/options?plan=pro&vertical=legal',
        );
        self::assertResponseIsSuccessful();
        $options = self::payload($client);
        self::assertNotContains('inventory', array_column($options['capabilities'], 'key'));

        $client->jsonRequest('POST', '/api/public/configurator/quote', [
            'plan' => 'enterprise',
            'vertical' => 'legal',
            'cycle' => 'monthly',
            'quantities' => ['users' => 1, 'locations' => 1, 'companies' => 1],
            'addons' => [],
        ]);
        self::assertResponseIsSuccessful();
        $quote = self::payload($client)['quote'];
        self::assertTrue($quote['proposal_required']);
        self::assertNull($quote['total_amount']);
    }

    /** @return array<string,mixed> */
    private static function payload($client): array
    {
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($payload);
        return $payload;
    }
}
