<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security;

use App\Infrastructure\Security\SafeResponsePolicy;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class SafeResponsePolicyTest extends WebTestCase
{
    public function testRichTextAllowlistDropsActiveMarkupAndAllUntrustedAttributes(): void
    {
        $policy = new SafeResponsePolicy();
        $input = '<div class="body"><p style="color:red" onmouseover="alert(1)">Hola '
            .'<strong id="private">mundo</strong><script>alert(2)</script>'
            .'<img src=x onerror=alert(3)><svg onload=alert(4)><circle /></svg>'
            .'<span onclick="alert(5)"><em>bien</em></span></p>'
            .'<ul data-value="unsafe"><li>uno</li><li><code>dos</code></li></ul></div>';

        $actual = $policy->sanitizeRichHtml($input);

        self::assertSame('<p>Hola <strong>mundo</strong><em>bien</em></p>'
            .'<ul><li>uno</li><li><code>dos</code></li></ul>', $actual);
        foreach (['<script', '<svg', '<img', 'onmouseover', 'onclick', 'onerror', 'style=', 'class='] as $unsafe) {
            self::assertStringNotContainsString($unsafe, $actual);
        }
    }

    public function testLinksAcceptOnlyExplicitSchemesOrSafeLocalPaths(): void
    {
        $policy = new SafeResponsePolicy();
        $valid = $policy->sanitizeRichHtml(
            '<p><a href="https://example.test/a?x=1&amp;y=2" onclick="x()">Externo</a> '
            .'<a href="/admin/ayuda">Interno</a></p>',
        );

        self::assertSame(
            '<p><a href="https://example.test/a?x=1&amp;y=2" rel="noopener noreferrer nofollow">Externo</a> '
            .'<a href="/admin/ayuda" rel="noopener noreferrer nofollow">Interno</a></p>',
            $valid,
        );

        $unsafeUris = [
            'javascript:alert(1)',
            'JaVaScRiPt:alert(1)',
            'java&#x73;cript:alert(1)',
            'java&amp;#x73;cript:alert(1)',
            'java&#9;script:alert(1)',
            'data:text/html,alert(1)',
            '//evil.test/phishing',
            '\\evil.test',
            'https://user:password@example.test/private',
            'mailto:help@example.test',
        ];
        foreach ($unsafeUris as $uri) {
            $output = $policy->sanitizeRichHtml('<a href="'.$uri.'">seguro</a>');
            self::assertSame('seguro', $output, 'Se aceptó enlace inseguro: '.$uri);
        }
    }

    public function testRejectsMalformedAndEncodedXssWithoutLosingTextEscaping(): void
    {
        $policy = new SafeResponsePolicy();

        self::assertSame(
            '<blockquote>&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; texto</blockquote>',
            $policy->sanitizeRichHtml('<blockquote>&lt;script&gt;alert("x")&lt;/script&gt; &amp; texto</blockquote>'),
        );
        self::assertSame(
            '<p>inicio <strong>fin</strong></p>',
            $policy->sanitizeRichHtml('<p>inicio <strong>fin</strong><iframe src="https://evil.test">malicia</iframe>'),
        );
        self::assertSame('', $policy->sanitizeRichHtml('<style>body{display:none}</style>'));
        self::assertSame('', $policy->sanitizeRichHtml(str_repeat('A', 65537)));
    }

    public function testTwigAutoConfigurationRendersOnlySanitizedFragment(): void
    {
        static::createClient();
        $twig = static::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $rendered = $twig->createTemplate('{{ snippet|condor_safe_html }}')->render([
            'snippet' => '<p><strong>Seguro</strong><script>alert(1)</script></p>',
        ]);
        self::assertSame('<p><strong>Seguro</strong></p>', $rendered);
    }

    public function testRealAdminAndApiHttpResponsesCarrySecurityHeadersAndPrivateCachePolicy(): void
    {
        $client = static::createClient();
        $cases = [
            ['/admin/login', 200],
            ['/api/v1/context', 401],
        ];

        foreach ($cases as [$route, $status]) {
            $client->request('GET', $route);
            self::assertResponseStatusCodeSame($status);
            $response = $client->getResponse();
            $csp = (string) $response->headers->get('Content-Security-Policy');

            foreach ([
                "default-src 'self'",
                "base-uri 'self'",
                "script-src 'self'",
                "object-src 'none'",
                "frame-ancestors 'none'",
                "form-action 'self'",
            ] as $directive) {
                self::assertStringContainsString($directive, $csp, $route);
            }
            self::assertStringNotContainsString("'unsafe-inline'", $csp);
            self::assertStringNotContainsString("'unsafe-eval'", $csp);
            self::assertStringNotContainsString('*', $csp);
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            // Login puede endurecer al valor explícito no-referrer.
            self::assertContains(
                $response->headers->get('Referrer-Policy'),
                ['no-referrer', 'strict-origin-when-cross-origin'],
            );
            self::assertSame(
                'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
                $response->headers->get('Permissions-Policy'),
            );
            self::assertTrue($response->headers->hasCacheControlDirective('private'));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        }
    }
}
