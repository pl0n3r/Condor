<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Observability;

use App\Infrastructure\Observability\RequestMetrics;
use App\Infrastructure\Observability\RequestMetricsSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestMetricsSubscriberTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/request-metrics-subscriber-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testUsesRouteNameWithoutPersistingRawPath(): void
    {
        $request = Request::create('/clientes/secret-token');
        $request->attributes->set('_route', 'app_customer_show');
        $request->server->set('REQUEST_TIME_FLOAT', microtime(true) - 0.001);

        $this->subscriber()->onKernelTerminate(
            new TerminateEvent(
                $this->createMock(HttpKernelInterface::class),
                $request,
                new Response('', 200),
            ),
        );

        $recent = RequestMetrics::recent($this->projectDir, 1);

        self::assertCount(1, $recent);
        self::assertSame('app_customer_show', $recent[0]['route']);
        self::assertStringNotContainsString('secret-token', $recent[0]['route']);
    }

    public function testUsesFixedMarkerWhenRouteIsMissing(): void
    {
        $request = Request::create('/reset/token-very-sensitive');
        $request->server->set('REQUEST_TIME_FLOAT', microtime(true) - 0.001);

        $this->subscriber()->onKernelTerminate(
            new TerminateEvent(
                $this->createMock(HttpKernelInterface::class),
                $request,
                new Response('', 404),
            ),
        );

        $recent = RequestMetrics::recent($this->projectDir, 1);

        self::assertCount(1, $recent);
        self::assertSame('(sin_ruta)', $recent[0]['route']);
    }

    private function subscriber(): RequestMetricsSubscriber
    {
        return new RequestMetricsSubscriber($this->projectDir);
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
