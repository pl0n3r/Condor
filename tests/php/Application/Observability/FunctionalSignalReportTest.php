<?php

declare(strict_types=1);

namespace App\Tests\Application\Observability;

use App\Application\Observability\FunctionalSignalReport;
use App\Domain\Observability\Entity\FunctionalSignal;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class FunctionalSignalReportTest extends TestCase
{
    public function testDailySeriesFillsEveryDayWithZerosAndAppliesRealCounts(): void
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['day' => $today, 'type' => FunctionalSignal::LOGIN_SUCCESS, 'aggregate_count' => '3'],
        ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $report = new FunctionalSignalReport($entityManager);
        $series = $report->dailySeries(3);

        self::assertCount(3, $series);
        self::assertSame(3, $series[$today][FunctionalSignal::LOGIN_SUCCESS]);
        self::assertSame(0, $series[$today][FunctionalSignal::TENANT_CREATED]);
    }

    public function testToCsvIncludesHeaderAndOneRowPerDay(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $csv = (new FunctionalSignalReport($entityManager))->toCsv(2);
        $lines = array_filter(explode("\n", trim($csv)));

        self::assertCount(3, $lines);
        self::assertStringStartsWith('fecha,', $lines[0]);
    }
}
