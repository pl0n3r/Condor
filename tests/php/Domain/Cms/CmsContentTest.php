<?php

declare(strict_types=1);

namespace App\Tests\Domain\Cms;

use App\Domain\Cms\Entity\CmsBlock;
use App\Domain\Cms\Entity\CmsPage;
use App\Domain\Cms\Entity\CmsTheme;
use App\Domain\Organization\Entity\Tenant;
use DomainException;
use PHPUnit\Framework\TestCase;

final class CmsContentTest extends TestCase
{
    public function testTenantScopedPagesThemesAndBlocksAreIsolated(): void
    {
        $tenantA = new Tenant('Acme', 'acme');
        $tenantB = new Tenant('Beta', 'beta');
        $themeA = new CmsTheme($tenantA, 'default', 'Default');
        $pageA = new CmsPage($tenantA, $themeA, 'inicio', 'Inicio');
        $blockA = new CmsBlock($tenantA, $pageA, 'text', ['text' => 'Hola'], 0);

        self::assertSame($tenantA->id(), $themeA->tenant()->id());
        self::assertSame($tenantA->id(), $pageA->tenant()->id());
        self::assertSame($tenantA->id(), $blockA->tenant()->id());
        self::assertSame($themeA, $pageA->theme());
        self::assertSame($pageA, $blockA->page());

        try {
            new CmsPage($tenantB, $themeA, 'intrusa', 'Intrusa');
            self::fail('Una página cross-tenant debe fallar cerrado.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        try {
            new CmsBlock($tenantB, $pageA, 'text', ['text' => 'Intruso']);
            self::fail('Un bloque cross-tenant debe fallar cerrado.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }

    public function testUnknownOrExecutableBlockTypesFailClosed(): void
    {
        $tenant = new Tenant('Acme', 'acme');
        $theme = new CmsTheme($tenant, 'default', 'Default');
        $page = new CmsPage($tenant, $theme, 'inicio', 'Inicio');
        $safe = new CmsBlock($tenant, $page, 'text', ['text' => 'Contenido seguro']);

        self::assertSame('text', $safe->type());

        foreach (['unknown', 'script', 'javascript', 'html', 'iframe', 'php'] as $type) {
            try {
                new CmsBlock($tenant, $page, $type, ['source' => 'ignored']);
                self::fail(sprintf('El tipo ejecutable/desconocido %s debe rechazarse.', $type));
            } catch (DomainException) {
                self::assertTrue(true);
            }
        }
    }
}
