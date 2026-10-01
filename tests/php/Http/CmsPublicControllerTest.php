<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Cms\Entity\CmsBlock;
use App\Domain\Cms\Entity\CmsPage;
use App\Domain\Cms\Entity\CmsTheme;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CmsPublicControllerTest extends WebTestCase
{
    public function testPublishedContentRendersForResolvedTenant(): void
    {
        $client = self::createClient();
        $fixture = $this->fixture($this->em());

        $client->request(
            'GET',
            '/'.$fixture['tenant']->slug().'/inicio',
            server: ['HTTP_HOST' => 'www.condorapp.com.co'],
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Inicio');
        self::assertSelectorTextContains('[data-block-type="hero"] h2', 'Bienvenido');
        self::assertSelectorTextContains('[data-block-type="text"]', 'Contenido público');
        self::assertSelectorExists('link[rel="canonical"]');
        self::assertSelectorExists('meta[name="description"]');
        self::assertResponseHeaderSame('cache-control', 'max-age=60, public');
        self::assertNotSame('', (string) $client->getResponse()->headers->get('etag'));
    }

    public function testDraftCrossTenantAndUnsafeContentNeverRender(): void
    {
        $client = self::createClient();
        $fixture = $this->fixture($this->em());

        $client->request(
            'GET',
            '/'.$fixture['tenant']->slug().'/borrador',
            server: ['HTTP_HOST' => 'www.condorapp.com.co'],
        );
        self::assertResponseStatusCodeSame(404);

        $client->request(
            'GET',
            '/'.$fixture['tenant']->slug().'/ajena',
            server: ['HTTP_HOST' => 'www.condorapp.com.co'],
        );
        self::assertResponseStatusCodeSame(404);

        $client->request(
            'GET',
            '/'.$fixture['tenant']->slug().'/inicio',
            server: ['HTTP_HOST' => 'www.condorapp.com.co'],
        );
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href^="javascript:"]');
        self::assertSelectorNotExists('img[src^="javascript:"]');
        self::assertStringNotContainsString(
            '<script>alert("cms")</script>',
            (string) $client->getResponse()->getContent(),
        );
    }

    /**
     * @return array{tenant: Tenant}
     */
    private function fixture(EntityManagerInterface $em): array
    {
        $suffix = strtolower(bin2hex(random_bytes(4)));
        $tenant = new Tenant('CMS público '.$suffix, 'cms-publico-'.$suffix);
        $theme = new CmsTheme($tenant, 'base', 'Base');
        $published = new CmsPage($tenant, $theme, 'inicio', 'Inicio');
        $hero = new CmsBlock(
            $tenant,
            $published,
            'hero',
            [
                'headline' => 'Bienvenido',
                'subheadline' => 'Contenido público',
                'cta' => ['label' => 'Comprar', 'href' => '/catalogo'],
            ],
            0,
        );
        $text = new CmsBlock(
            $tenant,
            $published,
            'text',
            ['text' => '<script>alert("cms")</script> Contenido público'],
            1,
        );
        $unsafeCta = new CmsBlock(
            $tenant,
            $published,
            'cta',
            ['label' => 'No ejecutar', 'href' => 'javascript:alert(1)'],
            2,
        );
        $unsafeImage = new CmsBlock(
            $tenant,
            $published,
            'image',
            ['alt' => 'No ejecutar', 'src' => 'javascript:alert(1)'],
            3,
        );
        $published->publish();

        $draft = new CmsPage($tenant, $theme, 'borrador', 'Borrador');

        $foreignTenant = new Tenant('CMS ajeno '.$suffix, 'cms-ajeno-'.$suffix);
        $foreignTheme = new CmsTheme($foreignTenant, 'base', 'Base');
        $foreign = new CmsPage($foreignTenant, $foreignTheme, 'ajena', 'Ajena');
        $foreign->publish();

        foreach ([
            $tenant,
            $theme,
            $published,
            $hero,
            $text,
            $unsafeCta,
            $unsafeImage,
            $draft,
            $foreignTenant,
            $foreignTheme,
            $foreign,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return ['tenant' => $tenant];
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
