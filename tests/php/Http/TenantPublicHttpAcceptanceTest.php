<?php

declare(strict_types=1);

namespace App\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * #761 AC-01 runs the real synthetic Symfony HTTP fixture in the MariaDB CI job.
 * The Python test alone is not discovered by the default Condor CI suites.
 */
final class TenantPublicHttpAcceptanceTest extends TestCase
{
    public function testPublicTenantHttpContractIsActuallyExecuted(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root.'/tests/test_tenant_public_http_contract.py');
        self::assertTrue(function_exists('proc_open'), 'PHP proc_open is required');

        $argv = [
            'python3', '-m', 'unittest',
            'tests.test_tenant_public_http_contract.TenantPublicoContratoHttpTests.test_acceptance',
            '-v',
        ];
        $pipes = [];
        $process = proc_open(
            $argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $root,
        );
        self::assertIsResource($process, 'Unable to execute AC-01 HTTP acceptance');

        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, 'AC-01 failed: '.$output);
        self::assertMatchesRegularExpression('/Ran 1 test\\b/', $output);
        self::assertMatchesRegularExpression('/\\bOK\\b/', $output);
    }
}
