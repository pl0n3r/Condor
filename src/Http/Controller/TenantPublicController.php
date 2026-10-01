<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Cms\PublicCmsPresentation;
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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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
        PublicCmsPresentation $cms,
        AppVersion $version,
    ): Response {
        if (!TenantResolver::isPlatformHost($request->getHost())) {
            $tenant = $tenantContext->current();
            if (!$tenant instanceof Tenant) {
                throw new NotFoundHttpException();
            }

            return $this->renderCmsPage(
                $request,
                $tenant,
                $tenant_slug,
                $storefront,
                $cms,
                $version,
            );
        }

        $tenant = $entityManager->getRepository(Tenant::class)
            ->findOneBy(['slug' => $tenant_slug]);
        if (!$tenant instanceof Tenant) {
            throw new NotFoundHttpException();
        }

        self::assertCurrentTenant($tenantContext, $tenant);

        return $this->render('tenant/index.html.twig', [
            'app_version' => $version->human(),
            'storefront' => $storefront->publicIdentity($tenant),
            'catalog' => $catalog->catalog(
                $tenant,
                self::catalogPage($request),
            ),
        ]);
    }

    #[Route(
        '/{tenant_slug}/{page_slug}',
        name: 'app_tenant_cms_public',
        requirements: [
            'tenant_slug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
            'page_slug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
        ],
        methods: ['GET'],
        priority: -90,
    )]
    public function page(
        string $tenant_slug,
        string $page_slug,
        Request $request,
        TenantContext $tenantContext,
        EntityManagerInterface $entityManager,
        StorefrontPresentation $storefront,
        PublicCmsPresentation $cms,
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

        self::assertCurrentTenant($tenantContext, $tenant);

        return $this->renderCmsPage(
            $request,
            $tenant,
            $page_slug,
            $storefront,
            $cms,
            $version,
        );
    }

    private function renderCmsPage(
        Request $request,
        Tenant $tenant,
        string $pageSlug,
        StorefrontPresentation $storefront,
        PublicCmsPresentation $cms,
        AppVersion $version,
    ): Response {
        $page = $cms->publishedPage($tenant, $pageSlug);
        if ($page === null) {
            throw new NotFoundHttpException();
        }

        $identity = $storefront->publicIdentity($tenant);
        $canonical = rtrim($identity['canonical'], '/').'/'.(string) $page['slug'];
        $response = $this->render('tenant/page.html.twig', [
            'app_version' => $version->human(),
            'storefront' => $identity,
            'page' => $page,
            'canonical' => $canonical,
        ]);
        $response->setPublic();
        $response->setMaxAge(300);
        $response->setSharedMaxAge(300);
        $response->setEtag(hash('sha256', (string) $page['etag'].'|'.$canonical));
        $response->headers->set('Vary', 'Host');
        $response->isNotModified($request);

        return $response;
    }

    private static function assertCurrentTenant(
        TenantContext $tenantContext,
        Tenant $tenant,
    ): void {
        $current = $tenantContext->current();
        if (!$current instanceof Tenant || $current->id() !== $tenant->id()) {
            throw new NotFoundHttpException();
        }
    }

    private static function catalogPage(Request $request): int
    {
        $page = $request->query->getInt('page', 1);
        if ($page < 1 || $page > PublicCatalogPresentation::MAX_PAGE) {
            throw new BadRequestHttpException(
                'La página solicitada no es válida.',
            );
        }

        return $page;
    }
}
