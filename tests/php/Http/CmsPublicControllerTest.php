<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Cms\Entity\CmsBlock;
use App\Domain\Cms\Entity\CmsPage;
use App\Domain\Cms\Entity\CmsTheme;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\TenantDomain;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CmsPublicControllerTest extends WebTestCase
{
    public function testPublishedContentRendersForTheResolvedTenant(): void
    {
        $client = self::createClient();
        $fixture = $this->fixture($this->em());
        $platformPath = '/'.$fixture['tenant']->slug().'/acerca';

        $client->request('GET', $platformPath);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Acerca');
        self::assertSelectorTextContains('[data-cms-block="text"]', 'Contenido público');
        self::assertSelectorTextContains('title', 'Acerca');
        self::assertNotNull($client->getResponse()->getEtag());
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('public'));

        $etag = $client->getResponse()->getEtag();
        self::assertIsString($etag);
        $client->request(
            'GET',
            $platformPath,
            [],
            [],
            ['HTTP_IF_NONE_MATCH' => $etag],
        );
        self::assertResponseStatusCodeSame(304);

        $client->request(
            'GET',
            '/acerca',
            [],
            [],
            ['HTTP_HOST' => $fixture['domain']->hostname()],
        );
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Acerca');
        self::assertSelectorExists(
            'link[rel="canonical"][href="https://'.$fixture['domain']->hostname().'/acerca"]',
        );
    }

    public function testDraftCrossTenantAndUnsafeContentNeverRender(): void
    {
        $client = self::createClient();
        $fixture = $this->fixture($this->em());

        $client->request('GET', '/'.$fixture['tenant']->slug().'/borrador');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/'.$fixture['tenant']->slug().'/acerca');
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Contenido ajeno', $content);
        self::assertStringNotContainsString('alert(1)', $content);
        self::assertStringNotContainsString('javascript:', $content);
    }

    /** @return array{tenant:Tenant,domain:TenantDomain} */
    private function fixture(EntityManagerInterface $em): array
    {
        $suffix = strtolower(bin2hex(random_bytes(4)));
        $tenant = new Tenant('CMS público '.$suffix, 'cms-publico-'.$suffix);
        $domain = new TenantDomain(
            $tenant,
            'cms-'.$suffix.'.example.test',
            true,
            true,
        );
        $theme = new CmsTheme($tenant, 'base', 'Base');
        $page = new CmsPage($tenant, $theme, 'acerca', 'Acerca');
        $publicText = new CmsBlock(
            $tenant,
            $page,
            'text',
            ['text' => 'Contenido público'],
            0,
        );
        $unsafeText = new CmsBlock(
            $tenant,
            $page,
            'text',
            ['text' => '<script>alert(1)</script>'],
            1,
        );
        $unsafeCta = new CmsBlock(
            $tenant,
            $page,
            'cta',
            ['label' => 'No ejecutar', 'href' => 'javascript:alert(1)'],
            2,
        );
        $page->publish();

        $draft = new CmsPage($tenant, $theme, 'borrador', 'Borrador');
        $draftBlock = new CmsBlock(
            $tenant,
            $draft,
            'text',
            ['text' => 'Secreto de borrador'],
            0,
        );

        $foreignTenant = new Tenant('CMS ajeno '.$suffix, 'cms-ajeno-'.$suffix);
        $foreignTheme = new CmsTheme($foreignTenant, 'base', 'Base');
        $foreignPage = new CmsPage($foreignTenant, $foreignTheme, 'acerca', 'Acerca ajena');
        $foreignBlock = new CmsBlock(
            $foreignTenant,
            $foreignPage,
            'text',
            ['text' => 'Contenido ajeno'],
            0,
        );
        $foreignPage->publish();

        foreach ([
            $tenant,
            $domain,
            $theme,
            $page,
            $publicText,
            $unsafeText,
            $unsafeCta,
            $draft,
            $draftBlock,
            $foreignTenant,
            $foreignTheme,
            $foreignPage,
            $foreignBlock,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return ['tenant' => $tenant, 'domain' => $domain];
    }

    private function em(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
