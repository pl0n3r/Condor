<?php

declare(strict_types=1);

namespace App\Tests\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Executes the real #759 HTTP acceptance in the disposable CI MariaDB runner.
 * The Python target generates a temporary Symfony WebTestCase and invokes
 * the installed PHPUnit binary. No external service, live data or secrets.
 */
final class InvitationHttpNegativesAcceptanceTest extends TestCase
{
    public function testPythonAcceptanceActuallyExecutesOneHttpScenario(): void
    {
        $root = dirname(__DIR__, 3);
        $process = new Process(
            [
                'python3',
                '-m',
                'unittest',
                'tests.test_invitation_http_negatives.InvitacionesNegativasHttpTests.test_acceptance',
                '-v',
            ],
            $root,
        );
        $process->setTimeout(240);
        $process->run();

        $evidence = $process->getOutput().$process->getErrorOutput();
        self::assertTrue($process->isSuccessful(), $evidence);
        self::assertMatchesRegularExpression('/Ran 1 test\b/', $evidence);
        self::assertMatchesRegularExpression('/\bOK\b/', $evidence);
    }
}
