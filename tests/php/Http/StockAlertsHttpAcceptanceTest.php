<?php

declare(strict_types=1);

namespace App\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Run #717 AC-01 in the disposable Symfony/MariaDB CI runner.
 * The fixture uses synthetic tenants only; no production access or secrets.
 */
final class StockAlertsHttpAcceptanceTest extends TestCase
{
    public function testStockAlertHttpContractRunsAgainstMariaDb(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists(
            $root.'/tests/test_stock_alerts_http_api.py',
            'The stock-alert HTTP AC-01 contract is missing.',
        );
        self::assertTrue(
            function_exists('proc_open'),
            'The disposable CI runner must support proc_open.',
        );

        // Array arguments avoid shell interpolation and credential leakage.
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            [
                'python3', '-m', 'unittest', '-v',
                'tests.test_stock_alerts_http_api.AlertasStockApiTests.test_acceptance',
            ],
            $descriptors,
            $pipes,
            $root,
        );
        self::assertIsResource($process, 'Could not start stock-alert AC-01.');

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $output = (string) $stdout.(string) $stderr;

        self::assertSame(0, $code, 'Stock-alert HTTP AC-01 failed: '.$output);
        self::assertMatchesRegularExpression(
            '/Ran 1 test\b/',
            $output,
            'AC-01 must actually discover and execute one Python test.',
        );
        self::assertMatchesRegularExpression(
            '/\bOK\b/',
            $output,
            'AC-01 must complete successfully, not skip its HTTP fixture.',
        );
    }
}
