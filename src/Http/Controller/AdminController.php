<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Identity\Entity\User;
use App\Shared\Version\AppVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AdminController extends AbstractController
{
    #[Route('/admin', name: 'app_admin', methods: ['GET'])]
    public function __invoke(
        AppVersion $version,
        CurrentTenantForUser $currentTenantForUser,
    ): Response {
        return $this->adminResponse($version, $currentTenantForUser, 'home');
    }

    #[Route('/admin/sitio', name: 'app_admin_site', methods: ['GET'])]
    public function site(
        AppVersion $version,
        CurrentTenantForUser $currentTenantForUser,
    ): Response {
        return $this->adminResponse($version, $currentTenantForUser, 'site');
    }

    private function adminResponse(
        AppVersion $version,
        CurrentTenantForUser $currentTenantForUser,
        string $section,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        if ($this->isGranted(User::ROLE_PLATFORM_OWNER)) {
            return $this->redirectToRoute('app_platform_owner');
        }

        $tenant = $currentTenantForUser->resolve($user);

        return $this->render('admin/index.html.twig', [
            'app_version' => $version->human(),
            'tenant_name' => $tenant->name(),
            'section' => $section,
            'hr_real_data_enabled' => getenv('HR_REAL_DATA_ENABLED') === 'true',
            'payroll_real_data_enabled' => getenv('PAYROLL_REAL_DATA_ENABLED') === 'true',
            'contracts_real_data_enabled' => getenv('CONTRACTS_REAL_DATA_ENABLED') === 'true',
            'social_security_real_data_enabled' => getenv('SOCIAL_SECURITY_REAL_DATA_ENABLED') === 'true',
            'finance_real_data_enabled' => getenv('FINANCE_REAL_DATA_ENABLED') === 'true',
            'ohs_real_data_enabled' => getenv('OHS_REAL_DATA_ENABLED') === 'true',
            'contracts_synthetic_api_enabled' => $this->getParameter('kernel.environment') === 'dev'
                && getenv('CONDOR_CONTRACTS_SYNTHETIC_API') === '1',
        ]);
    }
}
