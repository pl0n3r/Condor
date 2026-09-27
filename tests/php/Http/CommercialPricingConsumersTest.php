<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Commercial\CommercialCatalogSeeder;
use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class CommercialPricingConsumersTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $owner;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        (new CommercialCatalogSeeder($manager))->seed();

        $this->owner = new User(
            'pricing-owner-'.bin2hex(random_bytes(4)).'@example.test',
            'Propietario Pricing',
            [User::ROLE_PLATFORM_OWNER],
        );
        $manager->persist($this->owner);
        $manager->flush();
    }

    public function testPricingPageReadsCanonicalCatalog(): void
    {
        $crawler = $this->client->request('GET', '/precios');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Una base comercial');
        self::assertGreaterThanOrEqual(
            4,
            $crawler->filter('[data-plan-key]')->count(),
        );
        self::assertSame(
            '1',
            $crawler
                ->filter('[data-plan-key="business"]')
                ->attr('data-plan-version'),
        );
        self::assertStringContainsString(
            'COP',
            $crawler->filter('[data-plan-key="business"]')->text(),
        );
    }

    public function testConsumersShareCatalogIdentity(): void
    {
        $pricing = $this->client->request('GET', '/precios');
        self::assertResponseIsSuccessful();
        $pricingIdentity = self::pricingIdentity($pricing);

        $this->client->request('GET', '/api/public/configurator/catalog');
        self::assertResponseIsSuccessful();
        $configuratorIdentity = self::planIdentity(
            self::payload($this->client)['plans'],
        );

        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/adminpl0n3r/api/context');
        self::assertResponseIsSuccessful();
        $ownerIdentity = self::planIdentity(
            self::payload($this->client)['commercial_catalog'],
        );

        self::assertSame($pricingIdentity, $configuratorIdentity);
        self::assertSame($configuratorIdentity, $ownerIdentity);
    }

    public function testEnterpriseRemainsUnpricedAcrossConsumers(): void
    {
        $pricing = $this->client->request('GET', '/precios');
        self::assertResponseIsSuccessful();

        $enterpriseCard = $pricing->filter('[data-plan-key="enterprise"]');
        self::assertSame(1, $enterpriseCard->count());
        self::assertStringContainsString(
            'Solicitar propuesta',
            $enterpriseCard->text(),
        );
        self::assertSame(0, $enterpriseCard->filter('dd')->count());

        $this->client->request('GET', '/api/public/configurator/catalog');
        self::assertResponseIsSuccessful();
        $configEnterprise = self::plan(
            self::payload($this->client)['plans'],
            'enterprise',
        );
        self::assertTrue($configEnterprise['quote_required']);
        self::assertNull($configEnterprise['monthly_amount']);
        self::assertNull($configEnterprise['annual_amount']);

        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/adminpl0n3r/api/context');
        self::assertResponseIsSuccessful();
        $ownerEnterprise = self::plan(
            self::payload($this->client)['commercial_catalog'],
            'enterprise',
        );
        self::assertTrue($ownerEnterprise['quote_required']);
        self::assertNull($ownerEnterprise['monthly_amount']);
        self::assertNull($ownerEnterprise['annual_amount']);
    }

    /** @return array<string,int> */
    private static function pricingIdentity(Crawler $crawler): array
    {
        $identity = [];
        foreach ($crawler->filter('[data-plan-key]') as $node) {
            $key = (string) $node->getAttribute('data-plan-key');
            $identity[$key] = (int) $node->getAttribute('data-plan-version');
        }
        ksort($identity);

        return $identity;
    }

    /**
     * @param list<array<string,mixed>> $plans
     * @return array<string,int>
     */
    private static function planIdentity(array $plans): array
    {
        $identity = [];
        foreach ($plans as $plan) {
            $identity[(string) $plan['key']] = (int) $plan['version'];
        }
        ksort($identity);

        return $identity;
    }

    /**
     * @param list<array<string,mixed>> $plans
     * @return array<string,mixed>
     */
    private static function plan(array $plans, string $key): array
    {
        foreach ($plans as $plan) {
            if (($plan['key'] ?? null) === $key) {
                return $plan;
            }
        }

        self::fail(sprintf('Falta el plan comercial %s.', $key));
    }

    /** @return array<string,mixed> */
    private static function payload(KernelBrowser $client): array
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
