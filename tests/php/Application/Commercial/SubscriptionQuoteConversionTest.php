<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\CommercialCatalogSeeder;
use App\Application\Commercial\PlanQuoteService;
use App\Application\Commercial\PlatformCommercialTrialCreator;
use App\Application\Commercial\SelfServiceTrialReview;
use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Quote;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\Entity\SubscriptionConfiguration;
use App\Domain\Commercial\Entity\Vertical;
use App\Domain\Identity\Entity\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SubscriptionQuoteConversionTest extends KernelTestCase
{
    private EntityManagerInterface $manager;
    private PlatformCommercialTrialCreator $creator;
    private PlanQuoteService $quotes;

    protected function setUp(): void
    {
        self::bootKernel();

        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->manager = $manager;

        $seeder = static::getContainer()->get(CommercialCatalogSeeder::class);
        self::assertInstanceOf(CommercialCatalogSeeder::class, $seeder);
        $seeder->seed();

        $creator = static::getContainer()->get(
            PlatformCommercialTrialCreator::class,
        );
        self::assertInstanceOf(PlatformCommercialTrialCreator::class, $creator);
        $this->creator = $creator;

        $quotes = static::getContainer()->get(PlanQuoteService::class);
        self::assertInstanceOf(PlanQuoteService::class, $quotes);
        $this->quotes = $quotes;
    }

    public function testTrialApprovalUsesExactQuotePlanVerticalQuantitiesAndAddOns(): void
    {
        $at = $this->now()->modify('-1 second');
        $quote = $this->quotes->quote(
            'business',
            'commerce',
            'monthly',
            ['companies' => 1, 'locations' => 3, 'users' => 12],
            ['production-lite'],
            $at,
        );
        $tenantId = 'tenant-quote-success-'.bin2hex(random_bytes(4));
        $review = new SelfServiceTrialReview($this->creator);

        $result = $review->review(
            $this->owner(),
            $tenantId,
            $tenantId,
            $this->application($quote),
            'approve',
        );

        self::assertSame('approved', $result['status']);
        self::assertSame($quote->id(), $result['quote_id']);
        self::assertSame('configured', $result['trial']['status']);
        self::assertSame(
            'business',
            $result['trial']['subscription']['plan']['key'],
        );
        self::assertSame('converted', $quote->status());

        $subscription = $this->manager
            ->getRepository(Subscription::class)
            ->findOneBy(['tenantId' => $tenantId]);
        self::assertInstanceOf(Subscription::class, $subscription);
        self::assertSame(
            $quote->planVersion()->id(),
            $subscription->planVersion()->id(),
        );

        $configuration = $this->manager
            ->getRepository(SubscriptionConfiguration::class)
            ->findOneBy(['subscription' => $subscription]);
        self::assertInstanceOf(SubscriptionConfiguration::class, $configuration);
        self::assertSame(
            $quote->vertical()->id(),
            $configuration->vertical()->id(),
        );
        self::assertSame($quote->quantities(), $configuration->quantities());
        self::assertSame(
            $quote->addOns(),
            array_map(
                static fn (AddOn $addOn): string => $addOn->key(),
                $configuration->addOns(),
            ),
        );
        self::assertSame(
            1,
            $this->manager->getRepository(Subscription::class)->count([
                'tenantId' => $tenantId,
            ]),
        );
        self::assertSame(
            1,
            $this->manager
                ->getRepository(SubscriptionConfiguration::class)
                ->count(['subscription' => $subscription]),
        );
    }

    public function testMissingExpiredNonBusinessOrConvertedQuoteFailsClosedWithoutPartialSubscription(): void
    {
        $missingTenant = 'tenant-quote-missing-'.bin2hex(random_bytes(4));
        $this->assertCreatorRejected(
            $missingTenant,
            '01AAAAAAAAAAAAAAAAAAAAAAAA',
        );

        $expired = new Quote(
            $this->planVersion('business'),
            $this->vertical('commerce'),
            'monthly',
            ['companies' => 1, 'locations' => 3, 'users' => 10],
            [],
            199900,
            0,
            199900,
            false,
            $this->now()->modify('-31 days'),
        );
        $this->manager->persist($expired);
        $this->manager->flush();
        $expiredTenant = 'tenant-quote-expired-'.bin2hex(random_bytes(4));
        $this->assertCreatorRejected($expiredTenant, $expired->id());
        self::assertSame('draft', $expired->status());

        $nonBusiness = $this->quotes->quote(
            'pro',
            'commerce',
            'monthly',
            ['companies' => 5, 'locations' => 10, 'users' => 30],
            [],
            $this->now()->modify('-1 second'),
        );
        $nonBusinessTenant = 'tenant-quote-pro-'.bin2hex(random_bytes(4));
        $this->assertCreatorRejected($nonBusinessTenant, $nonBusiness->id());
        self::assertSame('draft', $nonBusiness->status());

        $converted = $this->quotes->quote(
            'business',
            'commerce',
            'monthly',
            ['companies' => 1, 'locations' => 3, 'users' => 10],
            [],
            $this->now()->modify('-1 second'),
        );
        $converted->convert();
        $this->manager->flush();
        $convertedTenant = 'tenant-quote-converted-'.bin2hex(random_bytes(4));
        $this->assertCreatorRejected($convertedTenant, $converted->id());
        self::assertSame('converted', $converted->status());
    }

    public function testCrossTenantReviewRemainsDeniedAndQuoteIsNotConverted(): void
    {
        $quote = $this->quotes->quote(
            'business',
            'commerce',
            'monthly',
            ['companies' => 1, 'locations' => 3, 'users' => 10],
            ['production-lite'],
            $this->now()->modify('-1 second'),
        );
        $review = new SelfServiceTrialReview($this->creator);
        $tenantA = 'tenant-review-a-'.bin2hex(random_bytes(4));
        $tenantB = 'tenant-review-b-'.bin2hex(random_bytes(4));

        try {
            $review->review(
                $this->owner(),
                $tenantA,
                $tenantB,
                $this->application($quote),
                'approve',
            );
            self::fail('El handoff cross-tenant debía fallar cerrado.');
        } catch (DomainException $exception) {
            self::assertSame(
                'Handoff de trial entre empresas no permitido.',
                $exception->getMessage(),
            );
        }

        self::assertSame('draft', $quote->status());
        self::assertSame(
            0,
            $this->manager->getRepository(Subscription::class)->count([
                'tenantId' => $tenantA,
            ]),
        );
        self::assertSame(
            0,
            $this->manager->getRepository(Subscription::class)->count([
                'tenantId' => $tenantB,
            ]),
        );
    }

    private function assertCreatorRejected(
        string $tenantId,
        string $quoteId,
    ): void {
        try {
            $this->creator->create($tenantId, $quoteId);
            self::fail('El Quote incompatible debía fallar cerrado.');
        } catch (DomainException) {
            self::assertSame(
                0,
                $this->manager->getRepository(Subscription::class)->count([
                    'tenantId' => $tenantId,
                ]),
            );
        }
    }

    /** @return array<string,mixed> */
    private function application(Quote $quote): array
    {
        return [
            'quote_id' => $quote->id(),
            'email' => 'trial-'.bin2hex(random_bytes(4)).'@example.test',
            'consent_recorded_at' => $this->now()->format('Y-m-d\TH:i:s.u\Z'),
            'status' => 'pending_owner_review',
        ];
    }

    private function owner(): User
    {
        return new User(
            'owner-quote-'.bin2hex(random_bytes(4)).'@example.test',
            'Owner',
            [User::ROLE_PLATFORM_OWNER],
        );
    }

    private function planVersion(string $key): PlanVersion
    {
        $plan = $this->manager->getRepository(Plan::class)
            ->findOneBy(['key' => $key]);
        self::assertInstanceOf(Plan::class, $plan);

        $version = $this->manager->getRepository(PlanVersion::class)
            ->findOneBy(['plan' => $plan, 'version' => 1]);
        self::assertInstanceOf(PlanVersion::class, $version);

        return $version;
    }

    private function vertical(string $key): Vertical
    {
        $vertical = $this->manager->getRepository(Vertical::class)
            ->findOneBy(['key' => $key]);
        self::assertInstanceOf(Vertical::class, $vertical);

        return $vertical;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
