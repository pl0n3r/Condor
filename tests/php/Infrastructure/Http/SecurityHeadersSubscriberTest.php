<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Infrastructure\Http\SecurityHeadersSubscriber;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SecurityHeadersSubscriberTest extends WebTestCase
{
    public function testPrivateRoutesOverridePreviouslyCacheableResponsesAtAnyStatus(): void
    {
        $paths = [
            '/admin',
            '/admin/login',
            '/adminpl0n3r',
            '/adminpl0n3r/api/context',
            '/api/v1',
            '/api/v1/context',
            '/support/diagnostics/'.str_repeat('a', 64),
            '/activar-cuenta/'.str_repeat('b', 64),
        ];

        foreach ($paths as $path) {
            foreach ([200, 403, 500] as $status) {
                $response = new Response('', $status, [
                    'Cache-Control' => 'public, max-age=3600',
                    'Surrogate-Control' => 'max-age=3600',
                    'Expires' => 'Wed, 21 Oct 2037 07:28:00 GMT',
                ]);

                $this->dispatch('https://www.condorapp.com.co'.$path, $response);

                self::assertTrue(
                    $response->headers->hasCacheControlDirective('no-store'),
                    $path.' HTTP '.$status,
                );
                self::assertTrue(
                    $response->headers->hasCacheControlDirective('private'),
                    $path.' HTTP '.$status,
                );
                self::assertFalse(
                    $response->headers->hasCacheControlDirective('public'),
                    $path.' HTTP '.$status,
                );
                self::assertFalse($response->headers->has('Surrogate-Control'));
                self::assertFalse($response->headers->has('Expires'));
            }
        }
    }

    public function testPublicAndSimilarLookingPathsKeepTheirCachePolicy(): void
    {
        foreach ([
            '/',
            '/empresa-del-barrio',
            '/health',
            '/administrator',
            '/adminpl0n3r-public',
            '/api/v11/context',
            '/support/diagnostics-public',
            '/activar-cuentas',
        ] as $path) {
            $response = new Response('', 200, [
                'Cache-Control' => 'public, max-age=3600',
            ]);

            $this->dispatch('https://www.condorapp.com.co'.$path, $response);

            self::assertTrue(
                $response->headers->hasCacheControlDirective('public'),
                $path,
            );
            self::assertFalse(
                $response->headers->hasCacheControlDirective('no-store'),
                $path,
            );
        }
    }

    public function testExistingSecurityHeadersAndSecureProductionHstsArePreserved(): void
    {
        $response = new Response();

        $this->dispatch('https://www.condorapp.com.co/admin/login', $response);

        self::assertStringContainsString(
            "frame-ancestors 'none'",
            (string) $response->headers->get('Content-Security-Policy'),
        );
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame(
            'strict-origin-when-cross-origin',
            $response->headers->get('Referrer-Policy'),
        );
        self::assertSame(
            'max-age=31536000',
            $response->headers->get('Strict-Transport-Security'),
        );

        $diagnostic = new Response('', 200, ['Referrer-Policy' => 'no-referrer']);
        $this->dispatch(
            'https://www.condorapp.com.co/support/diagnostics/'.str_repeat('a', 64),
            $diagnostic,
        );
        self::assertSame('no-referrer', $diagnostic->headers->get('Referrer-Policy'));

        $insecure = new Response();
        $this->dispatch('http://www.condorapp.com.co/admin/login', $insecure);
        self::assertFalse($insecure->headers->has('Strict-Transport-Security'));

        $development = new Response();
        $this->dispatch('https://www.condorapp.com.co/admin/login', $development, 'dev');
        self::assertFalse($development->headers->has('Strict-Transport-Security'));
    }

    public function testSubrequestsAreNotModified(): void
    {
        $response = new Response('', 200, ['Cache-Control' => 'public, max-age=300']);

        $this->dispatch(
            'https://www.condorapp.com.co/admin',
            $response,
            'prod',
            HttpKernelInterface::SUB_REQUEST,
        );

        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertFalse($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->has('Content-Security-Policy'));
    }

    public function testRealHttpLoginAndApiAreNotCacheableButHealthRemainsPublic(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/login');
        self::assertResponseIsSuccessful();
        self::assertTrue(
            $client->getResponse()->headers->hasCacheControlDirective('no-store'),
        );

        $client->request('GET', '/api/v1/context');
        self::assertResponseStatusCodeSame(401);
        self::assertTrue(
            $client->getResponse()->headers->hasCacheControlDirective('no-store'),
        );

        $client->request('GET', '/health');
        self::assertResponseIsSuccessful();
        self::assertFalse(
            $client->getResponse()->headers->hasCacheControlDirective('no-store'),
        );
    }

    private function dispatch(
        string $url,
        Response $response,
        string $environment = 'prod',
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
    ): void {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $event = new ResponseEvent(
            $kernel,
            Request::create($url),
            $requestType,
            $response,
        );

        (new SecurityHeadersSubscriber($environment))($event);
    }
}
