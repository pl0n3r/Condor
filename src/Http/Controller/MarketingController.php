<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Infrastructure\Tenancy\TenantContext;
use App\Shared\Version\AppVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Páginas públicas del sitio de la plataforma (producto, soluciones y seguridad).
 *
 * Son contenido estático renderizado en el servidor, sin JavaScript. Solo existen en el dominio de la
 * plataforma: en el dominio de un tenant la raíz muestra su tienda y estas rutas no existen.
 */
final class MarketingController extends AbstractController
{
    #[Route('/producto', name: 'app_marketing_producto', methods: ['GET'])]
    public function producto(TenantContext $tenantContext, AppVersion $version): Response
    {
        return $this->page('marketing/producto.html.twig', $tenantContext, $version);
    }

    #[Route('/soluciones', name: 'app_marketing_soluciones', methods: ['GET'])]
    public function soluciones(TenantContext $tenantContext, AppVersion $version): Response
    {
        return $this->page('marketing/soluciones.html.twig', $tenantContext, $version);
    }

    #[Route('/seguridad', name: 'app_marketing_seguridad', methods: ['GET'])]
    public function seguridad(TenantContext $tenantContext, AppVersion $version): Response
    {
        return $this->page('marketing/seguridad.html.twig', $tenantContext, $version);
    }

    private function page(string $template, TenantContext $tenantContext, AppVersion $version): Response
    {
        if ($tenantContext->current() !== null) {
            throw new NotFoundHttpException('Página no disponible.');
        }

        return $this->render($template, [
            'app_version' => $version->human(),
        ]);
    }
}
