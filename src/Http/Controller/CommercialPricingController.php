<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Commercial\CommercialCatalogReader;
use App\Shared\Version\AppVersion;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CommercialPricingController extends AbstractController
{
    #[Route('/precios', name: 'app_commercial_pricing', methods: ['GET'])]
    public function __invoke(
        CommercialCatalogReader $catalog,
        AppVersion $version,
    ): Response {
        return $this->render('pricing/index.html.twig', [
            'app_version' => $version->human(),
            'plans' => $catalog->current(
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            ),
        ]);
    }
}
