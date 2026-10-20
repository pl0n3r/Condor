<?php

declare(strict_types=1);

namespace App\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Runs #759 AC-01 from the real MariaDB/PHPUnit CI job, without requiring
 * symfony/process: Condor's locked Composer dependencies do not include it.
 */
final class InvitationHttpNegativesAcceptanceTest extends TestCase
{
    public function testPythonAcceptanceActuallyExecutesOneHttpScenario(): void
    {
        $root = dirname(__DIR__, 3);
        // Array argv is passed directly to PHP proc_open (no shell expansion).
        $argv = [
            'python3',
            '-m',
            'unittest',
            'tests.test_invitation_http_negatives.InvitacionesNegativasHttpTests.test_acceptance',
            '-v',
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open($argv, $descriptors, $pipes, $root);
        self::assertIsResource($process, 'No se pudo iniciar la prueba HTTP');

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $evidence = $stdout.$stderr;
        self::assertSame(0, $exit, $evidence);
        self::assertMatchesRegularExpression('/Ran 1 test\b/', $evidence);
        self::assertMatchesRegularExpression('/\bOK\b/', $evidence);
    }
}
