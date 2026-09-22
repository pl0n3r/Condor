<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Infrastructure\Tenancy\TenantContext;
use App\Infrastructure\Tenancy\TenantResolver;
use App\Application\Storefront\StorefrontPresentation;
use App\Shared\Version\AppVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class TenantPublicController extends AbstractController
{
    #[Route(
        '/{tenant_slug}',
        name: 'app_tenant_public',
        requirements: ['tenant_slug' => '(?!admin$|health$|api$)[a-z0-9]+(?:-[a-z0-9]+)*'],
        methods: ['GET'],
    )]
    public function __invoke(
        Request $request,
        TenantContext $tenantContext,
        AppVersion $version,
        StorefrontPresentation $storefront,
    ): Response
    {
        $tenant = $tenantContext->current();
        if ($tenant === null || !TenantResolver::isPlatformHost($request->getHost())
            || $request->attributes->get('tenant_slug') !== $tenant->slug()) {
            throw $this->createNotFoundException('Empresa no encontrada.');
        }

        return $this->render('tenant/index.html.twig', [
            'app_version' => $version->human(),
            'storefront' => $storefront->publicIdentity($tenant),
        ]);
    }
}
