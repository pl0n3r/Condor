<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Observability;

use App\Domain\Observability\Entity\ErrorIncident;
use App\Infrastructure\Observability\ErrorIncidentPresenter;
use PHPUnit\Framework\TestCase;

final class ErrorIncidentPresenterTest extends TestCase
{
    public function testPublicPayloadOnlyExposesSafeReference(): void
    {
        $presenter = new ErrorIncidentPresenter();
        $incident = $this->incident();

        $payload = $presenter->json($incident, false);
        $html = $presenter->html($incident, false);

        self::assertSame('internal_error', $payload['error']);
        self::assertSame($incident->id(), $payload['error_id']);
        self::assertArrayNotHasKey('diagnostic', $payload);

        self::assertStringContainsString($incident->id(), $html);
        self::assertStringNotContainsString('RuntimeException', $html);
        self::assertStringNotContainsString('mensaje interno', $html);
        self::assertStringNotContainsString('request-test', $html);
        self::assertStringNotContainsString('release-test', $html);
    }

    public function testPlatformOwnerReceivesSanitizedDiagnosticWithoutTrace(): void
    {
        $presenter = new ErrorIncidentPresenter();
        $incident = $this->incident();

        $payload = $presenter->json($incident, true);
        $html = $presenter->html($incident, true);

        self::assertSame('request-test', $payload['diagnostic']['request_id']);
        self::assertSame('app_platform_owner', $payload['diagnostic']['route']);
        self::assertSame('RuntimeException', $payload['diagnostic']['exception']);
        self::assertSame(
            'mensaje interno ya sanitizado',
            $payload['diagnostic']['message'],
        );
        self::assertArrayNotHasKey('trace', $payload['diagnostic']);

        self::assertStringContainsString('RuntimeException', $html);
        self::assertStringContainsString('mensaje interno ya sanitizado', $html);
        self::assertStringContainsString('/adminpl0n3r/diagnosticos', $html);
        self::assertStringNotContainsString('src/Secret.php', $html);
    }

    public function testHtmlEscapesDiagnosticValues(): void
    {
        $presenter = new ErrorIncidentPresenter();
        $incident = new ErrorIncident(
            requestId: 'request-test',
            status: 500,
            method: 'GET',
            routeName: '<script>route</script>',
            exceptionClass: 'RuntimeException<script>',
            message: '<img src=x onerror=alert(1)>',
            fingerprint: str_repeat('b', 64),
            version: '0.1.9',
            releaseSha: 'release-test',
            trace: [],
        );

        $html = $presenter->html($incident, true);

        self::assertStringNotContainsString('<script>route</script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString(
            '&lt;script&gt;route&lt;/script&gt;',
            $html,
        );
        self::assertStringContainsString(
            '&lt;img src=x onerror=alert(1)&gt;',
            $html,
        );
    }

    private function incident(): ErrorIncident
    {
        return new ErrorIncident(
            requestId: 'request-test',
            status: 500,
            method: 'GET',
            routeName: 'app_platform_owner',
            exceptionClass: 'RuntimeException',
            message: 'mensaje interno ya sanitizado',
            fingerprint: str_repeat('a', 64),
            version: '0.1.9',
            releaseSha: 'release-test',
            trace: [[
                'file' => 'src/Secret.php',
                'line' => 42,
                'call' => 'dangerousCall',
            ]],
        );
    }
}
