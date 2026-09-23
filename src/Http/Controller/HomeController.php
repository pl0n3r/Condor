<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Storefront\PublicCatalogPresentation;
use App\Application\Storefront\StorefrontPresentation;
use App\Infrastructure\Tenancy\TenantContext;
use App\Shared\Version\AppVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(
        Request $request,
        AppVersion $version,
        TenantContext $tenantContext,
        StorefrontPresentation $storefront,
        PublicCatalogPresentation $catalog,
    ): Response {
        $tenant = $tenantContext->current();

        if ($tenant !== null) {
            return $this->render('tenant/index.html.twig', [
                'app_version' => $version->human(),
                'storefront' => $storefront->publicIdentity($tenant),
                'catalog' => $catalog->catalog(
                    $tenant,
                    self::catalogPage($request),
                ),
            ]);
        }

        return $this->render('home/index.html.twig', [
            'app_version' => $version->human(),
        ]);
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
