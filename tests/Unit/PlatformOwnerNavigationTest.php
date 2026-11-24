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
            self::assertStringNotContainsString(
                'data-section="'.$section.'"',
                (string) $client->getResponse()->getContent(),
            );

            $client->loginUser($owner);
            $client->request('GET', $route);
            self::assertResponseStatusCodeSame(200);
            self::assertStringContainsString(
                'data-section="'.$section.'"',
                (string) $client->getResponse()->getContent(),
            );

            // The owner support catalogue must mirror /admin's server feature gates.
            foreach ([
                'customer-service-real-data-enabled' => 'CUSTOMER_SERVICE_REAL_DATA_ENABLED',
                'assets-real-data-enabled' => 'ASSETS_REAL_DATA_ENABLED',
                'operations-processes-real-data-enabled' => 'OPERATIONS_PROCESSES_REAL_DATA_ENABLED',
                'hr-real-data-enabled' => 'HR_REAL_DATA_ENABLED',
                'payroll-real-data-enabled' => 'PAYROLL_REAL_DATA_ENABLED',
                'contracts-real-data-enabled' => 'CONTRACTS_REAL_DATA_ENABLED',
                'social-security-real-data-enabled' => 'SOCIAL_SECURITY_REAL_DATA_ENABLED',
                'finance-real-data-enabled' => 'FINANCE_REAL_DATA_ENABLED',
                'ohs-real-data-enabled' => 'OHS_REAL_DATA_ENABLED',
                'legal-compliance-real-data-enabled' => 'LEGAL_COMPLIANCE_REAL_DATA_ENABLED',
                'governance-real-data-enabled' => 'GOVERNANCE_REAL_DATA_ENABLED',
            ] as $attribute => $environment) {
                $expected = getenv($environment) === 'true' ? 'true' : 'false';
                self::assertStringContainsString(
                    'data-'.$attribute.'="'.$expected.'"',
                    (string) $client->getResponse()->getContent(),
                );
            }

            // Las secciones de navegación son GET-only: no crean APIs mutantes.
            $client->request('POST', $route);
            self::assertResponseStatusCodeSame(405);
        }
    }

    public function testNavigationLinksAndDistinctPanelsRemainBoundToTheirSections(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/frontend/admin/PlatformOwnerApp.tsx');
        self::assertIsString($source);
        foreach ([
            '/adminpl0n3r' => 'Escritorio global',
            '/adminpl0n3r/empresas' => 'Empresas',
            '/adminpl0n3r/suscripciones' => 'Suscripciones',
            '/adminpl0n3r/catalogo' => 'Catálogo comercial',
            '/adminpl0n3r/staff' => 'Personal de Condor',
            '/adminpl0n3r/integraciones' => 'Integraciones globales',
        ] as $href => $label) {
            self::assertStringContainsString("href: '".$href."'", $source);
            self::assertStringContainsString("label: '".$label."'", $source);
        }

        // No fifth global menu or hard-coded tenant module shortcuts.
        self::assertStringNotContainsString("group: 'Módulos de empresa'", $source);
        $shell = file_get_contents(dirname(__DIR__, 2).'/frontend/admin/AdminShell.tsx');
        self::assertIsString($shell);
        self::assertStringContainsString("entry.group === 'Administración de plataforma'", $shell);
        self::assertStringContainsString("href: '/adminpl0n3r?view=auditoria'", $shell);

        self::assertStringContainsString("currentSection === 'catalogo'", $source);
        self::assertStringContainsString("currentSection === 'suscripciones'", $source);
        self::assertStringContainsString("currentSection === 'empresas'", $source);
        self::assertStringContainsString("id=\"commercial-portfolio-title\"", $source);
        self::assertStringContainsString("id=\"commercial-subscription-title\"", $source);
    }
}
