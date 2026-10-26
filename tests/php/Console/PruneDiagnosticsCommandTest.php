<?php

declare(strict_types=1);

namespace App\Tests\Console;

use App\Console\PruneDiagnosticsCommand;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PruneDiagnosticsCommandTest extends TestCase
{
    public function testRejectsInvalidRetentionWithoutIssuingAnyDelete(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');
        $command = new PruneDiagnosticsCommand($connection);

        foreach (['0', '91', '-1', '1.5', 'abc', ''] as $days) {
            $tester = new CommandTester($command);
            self::assertSame(Command::FAILURE, $tester->execute(['--days' => $days]));
            self::assertStringContainsString(
                'La retención debe estar entre 1 y 90 días.',
                $tester->getDisplay(),
            );
        }
    }

    public function testDefaultRetentionDeletesOnlyStrictlyExpiredRecordsWithUtcBindings(): void
    {
        $calls = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(
                static function (string $sql, array $params) use (&$calls): int {
                    $calls[] = [$sql, $params];

                    return count($calls) === 1 ? 2 : 3;
                },
            );

        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $tester = new CommandTester(new PruneDiagnosticsCommand($connection));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        self::assertCount(2, $calls);
        self::assertSame(
            'DELETE FROM condor_diagnostic_share WHERE expires_at < :now OR revoked_at IS NOT NULL',
            preg_replace('/\s+/', ' ', $calls[0][0]),
        );
        self::assertSame(
            'DELETE FROM condor_error_incident WHERE occurred_at < :cutoff',
            preg_replace('/\s+/', ' ', $calls[1][0]),
        );
        self::assertSame(['now'], array_keys($calls[0][1]));
        self::assertSame(['cutoff'], array_keys($calls[1][1]));

        $nowBound = $this->parseUtc($calls[0][1]['now']);
        $cutoffBound = $this->parseUtc($calls[1][1]['cutoff']);
        $this->assertWithinWindow($nowBound, $before, $after);
        $this->assertWithinWindow(
            $cutoffBound,
            $before->sub(new DateInterval('P14D')),
            $after->sub(new DateInterval('P14D')),
        );
        self::assertStringContainsString(
            'Purgados 2 enlaces y 3 incidentes antiguos.',
            $tester->getDisplay(),
        );
    }

    public function testMinimumAndMaximumRetentionBothRespectTheUtcCutoff(): void
    {
        foreach ([1, 90] as $days) {
            $calls = [];
            $connection = $this->createMock(Connection::class);
            $connection->expects(self::exactly(2))
                ->method('executeStatement')
                ->willReturnCallback(
                    static function (string $sql, array $params) use (&$calls): int {
                        $calls[] = [$sql, $params];

                        return 0;
                    },
                );

            $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $tester = new CommandTester(new PruneDiagnosticsCommand($connection));
            self::assertSame(
                Command::SUCCESS,
                $tester->execute(['--days' => (string) $days]),
            );
            $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            self::assertCount(2, $calls);
            $this->assertWithinWindow(
                $this->parseUtc($calls[1][1]['cutoff']),
                $before->sub(new DateInterval('P'.$days.'D')),
                $after->sub(new DateInterval('P'.$days.'D')),
            );
            self::assertStringContainsString(
                'Purgados 0 enlaces y 0 incidentes antiguos.',
                $tester->getDisplay(),
            );
        }
    }

    public function testRepeatedPruneIsSafeAndReportsZeroFurtherDeletions(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(4))
            ->method('executeStatement')
            ->willReturnOnConsecutiveCalls(3, 4, 0, 0);
        $command = new PruneDiagnosticsCommand($connection);
        $first = new CommandTester($command);
        $second = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $first->execute(['--days' => '7']));
        self::assertStringContainsString(
            'Purgados 3 enlaces y 4 incidentes antiguos.',
            $first->getDisplay(),
        );
        self::assertSame(Command::SUCCESS, $second->execute(['--days' => '7']));
        self::assertStringContainsString(
            'Purgados 0 enlaces y 0 incidentes antiguos.',
            $second->getDisplay(),
        );
    }

    private function parseUtc(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new DateTimeZone('UTC'),
        );
        self::assertInstanceOf(DateTimeImmutable::class, $date);

        return $date;
    }

    private function assertWithinWindow(
        DateTimeImmutable $actual,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
    ): void {
        self::assertGreaterThanOrEqual($start->getTimestamp() - 1, $actual->getTimestamp());
        self::assertLessThanOrEqual($end->getTimestamp(), $actual->getTimestamp());
    }
}
