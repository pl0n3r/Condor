<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Observability;

use App\Infrastructure\Observability\FatalLog;
use PHPUnit\Framework\TestCase;

/**
 * El visor deliberadamente no pasa por el firewall de Symfony (para seguir
 * funcionando aunque el login/la sesión estén rotos), así que se prueba
 * como lo que es: un script PHP plano servido de forma independiente, con
 * el servidor embebido de PHP — el mismo enfoque que RuntimeCheckTest usa
 * para public/runtime-check.php.
 */
final class PlatformFatalLogViewerTest extends TestCase
{
    private static ?string $baseUrl = null;

    /** @var resource|null */
    private static $serverProcess = null;

    public static function setUpBeforeClass(): void
    {
        $projectDir = dirname(__DIR__, 4);
        $port = 18391;
        self::$baseUrl = 'http://127.0.0.1:'.$port;

        $command = sprintf(
            '%s -S 127.0.0.1:%d -t %s',
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg($projectDir.'/public'),
        );

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $projectDir,
            null,
        );

        if ($process === false) {
            self::fail('No fue posible iniciar el servidor PHP embebido.');
        }

        self::$serverProcess = $process;

        for ($attempt = 0; $attempt < 40; ++$attempt) {
            $curl = curl_init(self::$baseUrl.'/runtime-check.php');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_TIMEOUT_MS, 200);
            curl_exec($curl);
            $ready = curl_errno($curl) === 0;

            if ($ready) {
                return;
            }

            usleep(50_000);
        }

        self::fail('El servidor PHP embebido no respondió a tiempo.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverProcess !== null) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }
    }

    public function testRejectsRequestsWithoutAValidToken(): void
    {
        [$status, $body] = $this->request('/platform-fatal-log.php');
        self::assertSame(404, $status);
        self::assertSame('', trim($body));

        [$status, $body] = $this->request(
            '/platform-fatal-log.php?token=incorrecto',
        );
        self::assertSame(404, $status);
        self::assertSame('', trim($body));
    }

    public function testReturnsRecentEntriesWithAValidToken(): void
    {
        $projectDir = dirname(__DIR__, 4);
        $appSecret = (string) getenv('APP_SECRET');
        self::assertNotSame('', $appSecret);

        $logPath = $projectDir.'/var/log/platform_fatal.log';
        @unlink($logPath);

        try {
            throw new \RuntimeException('sonda de prueba controlada');
        } catch (\RuntimeException $probe) {
            FatalLog::record($projectDir, 'TESTREF01', $probe);
        }

        $token = FatalLog::accessToken($appSecret);
        [$status, $body] = $this->request(
            '/platform-fatal-log.php?token='.urlencode($token),
        );

        self::assertSame(200, $status);
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $payload['status']);
        self::assertNotEmpty($payload['entries']);
        self::assertSame('TESTREF01', $payload['entries'][0]['id']);
        self::assertSame(
            'RuntimeException',
            $payload['entries'][0]['exception'],
        );
        self::assertStringContainsString(
            'sonda de prueba controlada',
            $payload['entries'][0]['message'],
        );

        @unlink($logPath);
    }

    /** @return array{0: int, 1: string} */
    private function request(string $path): array
    {
        $curl = curl_init(self::$baseUrl.$path);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 5);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        return [$status, is_string($body) ? $body : ''];
    }
}
