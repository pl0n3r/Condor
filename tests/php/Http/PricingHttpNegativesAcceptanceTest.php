<?php

declare(strict_types=1);

namespace App\Tests\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Condor #755: execute AC-01 inside the real disposable MariaDB backend job.
 *
 * A tests/php/ change selects the Backend PHP / MariaDB CI gate; the Python
 * contract then invokes its isolated Symfony WebTestCase fixture. Fail closed
 * on missing PHP, PHPUnit, invalid HTTP status, or any persistence regression.
 */
final class PricingHttpNegativesAcceptanceTest extends TestCase
{
    public function testPricingHttpNegativesExecuteAgainstTestDatabase(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists(
            $root.'/tests/test_pricing_http_negatives.py',
            'Missing required AC-01 Python acceptance contract.',
        );

        $process = new Process(
            ['python3', 'tests/test_pricing_http_negatives.py', '-v'],
            $root,
        );
        $process->setTimeout(240);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();
        self::assertSame(
            0,
            $process->getExitCode(),
            'Pricing AC-01 HTTP contract failed: '.$output,
        );
        self::assertMatchesRegularExpression(
            '/Ran 1 test\b/',
            $output,
            'Exactly one Python acceptance target must execute.',
        );
        self::assertMatchesRegularExpression(
            '/\bOK\b/',
            $output,
            'AC-01 must pass; a skipped fixture is not acceptance.',
        );
    }
}
