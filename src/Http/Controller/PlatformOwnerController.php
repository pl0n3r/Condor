<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Identity\Entity\User;
use App\Shared\Version\AppVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlatformOwnerController extends AbstractController
{
    #[Route(
        '/adminpl0n3r',
        name: 'app_platform_owner',
        defaults: ['section' => 'control'],
        methods: ['GET'],
    )]
    #[Route(
        '/adminpl0n3r/empresas',
        name: 'app_platform_owner_tenants',
        defaults: ['section' => 'empresas'],
        methods: ['GET'],
    )]
    #[Route(
        '/adminpl0n3r/suscripciones',
        name: 'app_platform_owner_subscriptions',
        defaults: ['section' => 'suscripciones'],
        methods: ['GET'],
    )]
    #[Route(
        '/adminpl0n3r/catalogo',
        name: 'app_platform_owner_catalog',
        defaults: ['section' => 'catalogo'],
        methods: ['GET'],
    )]
    #[Route(
        '/adminpl0n3r/staff',
        name: 'app_platform_owner_staff',
        defaults: ['section' => 'staff'],
        methods: ['GET'],
    )]
    #[Route(
        '/adminpl0n3r/integraciones',
        name: 'app_platform_owner_integrations',
        defaults: ['section' => 'integraciones'],
        methods: ['GET'],
    )]
    public function __invoke(AppVersion $version, string $section): Response
    {
        $this->denyAccessUnlessGranted(User::ROLE_PLATFORM_OWNER);

        return $this->render('platform_owner/index.html.twig', [
            'app_version' => $version->human(),
            'section' => $section,
            'customer_service_real_data_enabled' => getenv('CUSTOMER_SERVICE_REAL_DATA_ENABLED') === 'true',
            'assets_real_data_enabled' => getenv('ASSETS_REAL_DATA_ENABLED') === 'true',
            'operations_processes_real_data_enabled' => getenv('OPERATIONS_PROCESSES_REAL_DATA_ENABLED') === 'true',
            'hr_real_data_enabled' => getenv('HR_REAL_DATA_ENABLED') === 'true',
            'payroll_real_data_enabled' => getenv('PAYROLL_REAL_DATA_ENABLED') === 'true',
            'contracts_real_data_enabled' => getenv('CONTRACTS_REAL_DATA_ENABLED') === 'true',
            'social_security_real_data_enabled' => getenv('SOCIAL_SECURITY_REAL_DATA_ENABLED') === 'true',
            'finance_real_data_enabled' => getenv('FINANCE_REAL_DATA_ENABLED') === 'true',
            'ohs_real_data_enabled' => getenv('OHS_REAL_DATA_ENABLED') === 'true',
            'legal_compliance_real_data_enabled' => getenv('LEGAL_COMPLIANCE_REAL_DATA_ENABLED') === 'true',
            'governance_real_data_enabled' => getenv('GOVERNANCE_REAL_DATA_ENABLED') === 'true',
        ]);
    }
}
