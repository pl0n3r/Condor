<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Observability;

use App\Infrastructure\Observability\RequestMetrics;
use PHPUnit\Framework\TestCase;

final class RequestMetricsTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/request-metrics-test-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testSummaryIsEmptyWithoutRecordedRequests(): void
    {
        $summary = RequestMetrics::summary($this->projectDir);

        self::assertSame(0, $summary['count']);
        self::assertSame(0.0, $summary['p50_ms']);
        self::assertSame(0.0, $summary['error_rate']);
    }

    public function testSummaryComputesPercentilesAndErrorRate(): void
    {
        $durations = [10, 20, 30, 40, 100];
        foreach ($durations as $i => $duration) {
            RequestMetrics::record(
                $this->projectDir,
                'app_test_route',
                $i === 4 ? 500 : 200,
                (float) $duration,
                50 * 1_048_576,
            );
        }

        $summary = RequestMetrics::summary($this->projectDir);

        self::assertSame(5, $summary['count']);
        self::assertSame(30.0, $summary['p50_ms']);
        self::assertSame(100.0, $summary['p95_ms']);
        self::assertSame(20.0, $summary['error_rate']);
        self::assertSame(50.0, $summary['avg_memory_mb']);
    }

    public function testRecentReturnsMostRecentFirst(): void
    {
        RequestMetrics::record($this->projectDir, 'route_a', 200, 10.0, 1_048_576);
        RequestMetrics::record($this->projectDir, 'route_b', 200, 20.0, 1_048_576);

        $recent = RequestMetrics::recent($this->projectDir);

        self::assertCount(2, $recent);
        self::assertSame('route_b', $recent[0]['route']);
        self::assertSame('route_a', $recent[1]['route']);
    }

    public function testRetentionKeepsOnlyLatestFiveHundredEntries(): void
    {
        for ($i = 0; $i < 505; $i++) {
            RequestMetrics::record(
                $this->projectDir,
                sprintf('route_%03d', $i),
                200,
                (float) $i,
                1_048_576,
            );
        }

        $recent = RequestMetrics::recent($this->projectDir, 1000);

        self::assertCount(500, $recent);
        self::assertSame('route_504', $recent[0]['route']);
        self::assertSame('route_005', $recent[499]['route']);
    }

    public function testRecordNeverThrowsWhenProjectDirCannotContainLogDirectory(): void
    {
        $fileProjectDir = $this->projectDir.'/not-a-directory';
        file_put_contents($fileProjectDir, 'fixture');

        RequestMetrics::record($fileProjectDir, 'route', 200, 5.0, 1_048_576);

        self::assertFileDoesNotExist($fileProjectDir.'/var/log/request_metrics.log');
        self::assertSame('fixture', file_get_contents($fileProjectDir));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path.'/'.$item;
            is_dir($itemPath) ? $this->removeDirectory($itemPath) : unlink($itemPath);
        }

        rmdir($path);
    }
}
