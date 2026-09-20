<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Infrastructure\Tenancy\TenantContext;
use App\Shared\Version\AppVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(AppVersion $version, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->current();

        if ($tenant !== null) {
            return $this->render('tenant/index.html.twig', [
                'app_version' => $version->human(),
                'tenant_name' => $tenant->name(),
            ]);
        }

        return $this->render('home/index.html.twig', [
            'app_version' => $version->human(),
        ]);
    }
}
