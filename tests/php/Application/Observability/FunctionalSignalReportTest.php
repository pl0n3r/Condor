<?php

declare(strict_types=1);

namespace App\Tests\Application\Observability;

use App\Application\Observability\FunctionalSignalReport;
use App\Domain\Observability\Entity\FunctionalSignal;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FunctionalSignalReportTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    /** @var list<string> */
    private array $createdSignalIds = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    protected function tearDown(): void
    {
        foreach ($this->createdSignalIds as $id) {
            $this->entityManager->getConnection()->executeStatement(
                'DELETE FROM condor_functional_signal WHERE id = :id',
                ['id' => $id],
            );
        }

        parent::tearDown();
    }

    public function testDailySeriesGroupsPersistedSignalsAcrossUtcDayBoundary(): void
    {
        $report = new FunctionalSignalReport($this->entityManager);
        $before = $report->dailySeries(2);

        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTime(0, 0);
        $yesterday = $today->modify('-1 day');

        $yesterdaySignal = $this->persistSignal(FunctionalSignal::LOGIN_SUCCESS);
        $todayLogin = $this->persistSignal(FunctionalSignal::LOGIN_SUCCESS);
        $todayTenant = $this->persistSignal(FunctionalSignal::TENANT_CREATED);
        $this->entityManager->flush();

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            'UPDATE condor_functional_signal SET created_at = :created_at WHERE id = :id',
            [
                'created_at' => $yesterday->setTime(23, 59, 59)->format('Y-m-d H:i:s'),
                'id' => $yesterdaySignal->id(),
            ],
        );
        $connection->executeStatement(
            'UPDATE condor_functional_signal SET created_at = :created_at WHERE id = :id',
            [
                'created_at' => $today->setTime(0, 0, 1)->format('Y-m-d H:i:s'),
                'id' => $todayLogin->id(),
            ],
        );
        $connection->executeStatement(
            'UPDATE condor_functional_signal SET created_at = :created_at WHERE id = :id',
            [
                'created_at' => $today->setTime(0, 0, 2)->format('Y-m-d H:i:s'),
                'id' => $todayTenant->id(),
            ],
        );

        $after = $report->dailySeries(2);
        $yesterdayKey = $yesterday->format('Y-m-d');
        $todayKey = $today->format('Y-m-d');

        self::assertSame(
            $before[$yesterdayKey][FunctionalSignal::LOGIN_SUCCESS] + 1,
            $after[$yesterdayKey][FunctionalSignal::LOGIN_SUCCESS],
        );
        self::assertSame(
            $before[$todayKey][FunctionalSignal::LOGIN_SUCCESS] + 1,
            $after[$todayKey][FunctionalSignal::LOGIN_SUCCESS],
        );
        self::assertSame(
            $before[$todayKey][FunctionalSignal::TENANT_CREATED] + 1,
            $after[$todayKey][FunctionalSignal::TENANT_CREATED],
        );
    }

    public function testToCsvUsesConfiguredDatabaseAndIncludesOneRowPerDay(): void
    {
        $csv = (new FunctionalSignalReport($this->entityManager))->toCsv(2);
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        self::assertCount(3, $lines);
        self::assertStringStartsWith('fecha,', $lines[0]);
    }

    private function persistSignal(string $type): FunctionalSignal
    {
        $signal = new FunctionalSignal($type);
        $this->entityManager->persist($signal);
        $this->createdSignalIds[] = $signal->id();

        return $signal;
    }
}
