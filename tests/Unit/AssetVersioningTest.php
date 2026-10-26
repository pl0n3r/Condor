<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Real Twig-rendered regression for versioned /build/ URLs (#962).
 *
 * Run standalone with: vendor/bin/simple-phpunit tests/Unit/AssetVersioningTest.php
 */
final class AssetVersioningTest extends TestCase
{
    public function testBuildUrlsAreVersionedOnEveryAffectedPage(): void
    {
        $cases = [
            'admin/index.html.twig' => ['/build/admin.css', '/build/admin.js'],
            'platform_owner/index.html.twig' => ['/build/admin.css', '/build/admin.js'],
            'platform_owner/diagnostics.html.twig' => ['/build/admin.css'],
            'platform_owner/diagnostic_share.html.twig' => ['/build/admin.css'],
            'configurator/index.html.twig' => ['/build/configurator.js'],
        ];

        foreach ($cases as $template => $paths) {
            $html = self::renderPage($template, '0.1.195');
            preg_match_all('~(?:href|src)="(/build/[^"]+)"~', $html, $matches);
            self::assertCount(count($paths), $matches[1], $template);

            $actual = $matches[1];
            sort($actual);
            $expected = array_map(
                static fn (string $path): string => $path.'?v=0.1.195',
                $paths,
            );
            sort($expected);
            self::assertSame($expected, $actual, $template);
        }
    }

    public function testCacheKeyChangesWhenAppVersionChanges(): void
    {
        foreach ([
            'admin/index.html.twig',
            'platform_owner/index.html.twig',
            'platform_owner/diagnostics.html.twig',
            'platform_owner/diagnostic_share.html.twig',
            'configurator/index.html.twig',
        ] as $template) {
            $old = self::renderPage($template, '0.1.195');
            $new = self::renderPage($template, '0.1.196');
            self::assertStringContainsString('?v=0.1.195"', $old, $template);
            self::assertStringContainsString('?v=0.1.196"', $new, $template);
            self::assertStringNotContainsString('?v=0.1.195"', $new, $template);
        }
    }

    private static function renderPage(string $template, string $version): string
    {
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 2).'/templates'),
        );
        $twig->addFunction(new TwigFunction(
            'path',
            static fn (string $route, array $parameters = []): string => '/'.$route,
        ));
        $twig->addFunction(new TwigFunction(
            'csrf_token',
            static fn (string $id): string => 'synthetic-csrf',
        ));

        return $twig->render($template, [
            'app_version' => $version,
            'section' => 'control',
            'tenant_name' => 'Empresa de prueba',
            'incidents' => [],
            'shares' => [],
            'request_metrics' => ['count' => 0],
            'fatal_log_url' => null,
            'share' => ['expiresAt' => new DateTimeImmutable('2026-10-26T00:00:00Z')],
            'share_url' => 'https://example.test/diagnostico',
        ]);
    }
}
