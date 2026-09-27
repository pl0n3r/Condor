<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\CommercialCatalogReader;
use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\Capability;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\Vertical;
use App\Domain\Commercial\PlanVersionTimeline;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CommercialCatalogActivationTest extends KernelTestCase
{
    public function testSeedCommandIsIdempotent(): void
    {
        $tester = $this->commandTester();

        self::assertSame(0, $tester->execute([]));
        self::assertSame(0, $tester->execute([]));

        $manager = $this->entityManager();
        self::assertCount(4, $manager->getRepository(Plan::class)->findAll());
        self::assertCount(5, $manager->getRepository(Vertical::class)->findAll());
        self::assertCount(
            17,
            $manager->getRepository(Capability::class)->findAll(),
        );
        self::assertCount(6, $manager->getRepository(AddOn::class)->findAll());
    }

    public function testSuccessfulActivationExposesFourPlans(): void
    {
        $tester = $this->commandTester();
        self::assertSame(0, $tester->execute([]));

        $manager = $this->entityManager();
        $reader = new CommercialCatalogReader(
            $manager,
            new PlanVersionTimeline(),
        );

        self::assertCount(
            4,
            $reader->current(
                new DateTimeImmutable('2026-09-27T12:00:00Z'),
            ),
        );
    }

    private function commandTester(): CommandTester
    {
        if (!self::$booted) {
            self::bootKernel();
        }

        $application = new Application(self::$kernel);

        return new CommandTester(
            $application->find('app:commercial:seed'),
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
