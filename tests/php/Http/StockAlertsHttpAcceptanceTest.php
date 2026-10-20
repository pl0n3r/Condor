<?php

declare(strict_types=1);

namespace App\Tests\Http;

use PHPUnit\Framework\TestCase;

/** Validate #717 through a real disposable Symfony/MariaDB HTTP acceptance. */
final class StockAlertsHttpAcceptanceTest extends TestCase
{
    public function testStockAlertHttpContractRunsAgainstMariaDb(): void
    {
        $checkout = dirname(__DIR__, 3);
        self::assertFileExists($checkout.'/tests/test_stock_alerts_http_api.py');
        self::assertTrue(function_exists('proc_open'), 'PHP proc_open is required');

        $command = [
            'python3', '-m', 'unittest',
            'tests.test_stock_alerts_http_api.AlertasStockApiTests.test_acceptance', '-v',
        ];
        $streams = [];
        $child = proc_open(
            $command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $streams, $checkout,
        );
        self::assertIsResource($child, 'Failed to start AC-01 HTTP acceptance');

        fclose($streams[0]);
        $output = (string) stream_get_contents($streams[1]);
        $output .= (string) stream_get_contents($streams[2]);
        foreach ([$streams[1], $streams[2]] as $stream) {
            fclose($stream);
        }
        $exit = proc_close($child);

        self::assertSame(0, $exit, 'AC-01 failed: '.$output);
        self::assertMatchesRegularExpression('/Ran 1 test\b/', $output);
        self::assertMatchesRegularExpression('/\bOK\b/', $output);
    }
}
