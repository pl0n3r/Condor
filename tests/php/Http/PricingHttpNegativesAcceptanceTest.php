<?php

declare(strict_types=1);

namespace App\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Condor #755: run the full HTTP negative contract in the disposable MariaDB
 * backend job, without introducing an extra Symfony Process dependency.
 */
final class PricingHttpNegativesAcceptanceTest extends TestCase
{
    public function testPricingHttpNegativesExecuteAgainstTestDatabase(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists(
            $root.'/tests/test_pricing_http_negatives.py',
            'Required AC-01 Python contract is missing.',
        );
        self::assertTrue(
            function_exists('proc_open'),
            'The CI runner must support PHP proc_open to execute AC-01.',
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            ['python3', '-m', 'unittest', '-v',
                'tests.test_pricing_http_negatives.PreciosNegativasHttpTests.test_acceptance'],
            $descriptors,
            $pipes,
            $root,
        );
        self::assertIsResource($process, 'Could not launch Python AC-01.');

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $output = (string) $stdout.(string) $stderr;

        self::assertSame(0, $code, 'Pricing HTTP AC-01 failed: '.$output);
        self::assertMatchesRegularExpression(
            '/Ran 1 test\b/',
            $output,
            'AC-01 must execute exactly one Python test.',
        );
        self::assertMatchesRegularExpression(
            '/\bOK\b/',
            $output,
            'AC-01 must pass, not skip its fixture.',
        );
    }
}
