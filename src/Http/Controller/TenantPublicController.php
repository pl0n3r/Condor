<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Storefront\PublicCatalogPresentation;
use App\Application\Storefront\StorefrontPresentation;
use App\Domain\Organization\Entity\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use App\Infrastructure\Tenancy\TenantResolver;
use App\Shared\Version\AppVersion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class TenantPublicController extends AbstractController
{
    #[Route(
        '/{tenant_slug}',
        name: 'app_tenant_public',
        requirements: ['tenant_slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'],
        methods: ['GET'],
        priority: -100,
    )]
    public function __invoke(
        string $tenant_slug,
        Request $request,
        TenantContext $tenantContext,
        EntityManagerInterface $entityManager,
        StorefrontPresentation $storefront,
        PublicCatalogPresentation $catalog,
        AppVersion $version,
    ): Response {
        if (!TenantResolver::isPlatformHost($request->getHost())) {
            throw new NotFoundHttpException();
        }

        $tenant = $entityManager->getRepository(Tenant::class)
            ->findOneBy(['slug' => $tenant_slug]);
        if (!$tenant instanceof Tenant) {
            throw new NotFoundHttpException();
        }

        $current = $tenantContext->current();
        if (!$current instanceof Tenant || $current->id() !== $tenant->id()) {
            throw new NotFoundHttpException();
        }

        return $this->render('tenant/index.html.twig', [
            'app_version' => $version->human(),
            'storefront' => $storefront->publicIdentity($tenant),
            'catalog' => $catalog->catalog($tenant),
        ]);
    }
}
