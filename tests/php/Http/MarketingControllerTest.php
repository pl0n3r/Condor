<?php

declare(strict_types=1);

namespace App\Tests\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MarketingControllerTest extends WebTestCase
{
    public function testPublicPagesRenderOnPlatformDomain(): void
    {
        $pages = [
            '/' => 'Digitaliza tu negocio',
            '/producto' => 'Ocho módulos',
            '/soluciones' => 'Hay un Condor para cada negocio',
            '/seguridad' => 'Tu información, aparte',
        ];

        foreach ($pages as $path => $heading) {
            $client = self::createClient();
            $client->request('GET', $path, server: ['HTTP_HOST' => 'www.condorapp.com.co']);

            self::assertResponseIsSuccessful($path);
            self::assertSelectorTextContains('h1', $heading);
            self::assertSelectorExists('link[rel="canonical"]');
            self::assertSelectorExists('meta[name="description"]');
            self::assertSelectorExists('link[href="/marketing.css"]');
            self::assertSelectorNotExists('script');
            self::ensureKernelShutdown();
        }
    }

    public function testNavigationLinksTheThreePages(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/producto', server: ['HTTP_HOST' => 'www.condorapp.com.co']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('nav.mk-nav a[href="/producto"][aria-current="page"]'));
        self::assertCount(1, $crawler->filter('nav.mk-nav a[href="/soluciones"]'));
        self::assertCount(1, $crawler->filter('nav.mk-nav a[href="/seguridad"]'));
    }
}
