<?php

declare(strict_types=1);

use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformOwnerNavigationTest extends WebTestCase
{
    public function testNewSectionsDenyNonPlatformOwnersAndRenderForOwner(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $suffix = bin2hex(random_bytes(5));
        $owner = new User('platform-nav-owner-'.$suffix.'@example.test', 'Owner', [User::ROLE_PLATFORM_OWNER]);
        $other = new User('platform-nav-tenant-'.$suffix.'@example.test', 'Tenant user');
        $em->persist($owner);
        $em->persist($other);
        $em->flush();

        foreach ([
            '/adminpl0n3r/suscripciones' => 'suscripciones',
            '/adminpl0n3r/catalogo' => 'catalogo',
        ] as $route => $section) {
            $client->loginUser($other);
            $client->request('GET', $route);
            self::assertResponseStatusCodeSame(403);

            $client->loginUser($owner);
            $client->request('GET', $route);
            self::assertResponseStatusCodeSame(200);
            self::assertStringContainsString(
                'data-section="'.$section.'"',
                (string) $client->getResponse()->getContent(),
            );
        }
    }

    public function testNavigationLinksAndDistinctPanelsRemainBoundToTheirSections(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/frontend/admin/PlatformOwnerApp.tsx');
        self::assertIsString($source);
        foreach ([
            '/adminpl0n3r/suscripciones' => 'Plan y suscripciones',
            '/adminpl0n3r/catalogo' => 'Catálogo comercial',
        ] as $href => $label) {
            self::assertStringContainsString("href: '".$href."'", $source);
            self::assertStringContainsString("label: '".$label."'", $source);
        }

        self::assertStringContainsString("currentSection === 'catalogo'", $source);
        self::assertStringContainsString("currentSection === 'suscripciones'", $source);
        self::assertStringContainsString("currentSection === 'empresas'", $source);
        self::assertStringContainsString("id=\"commercial-portfolio-title\"", $source);
        self::assertStringContainsString("id=\"commercial-subscription-title\"", $source);
    }
}
