<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identifiers\ScanResolver;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Labels\LabelRenderer;
use App\Application\Labels\LabelTemplate;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identifiers\ScanIdentifier;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SKU-derived scan codes, scoped to an authenticated branch and tenant.
 *
 * A durable custom-code registry is not yet present. Never infer one from a
 * caller-supplied tenant ID, nor expose another tenant's codes or labels.
 */
final class ScanController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly LabelRenderer $labels,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/inventory/scan/{code}',
        name: 'api_inventory_scan_resolve',
        methods: ['GET'],
    )]
    public function resolve(string $branchId, string $code): JsonResponse
    {
        [, $tenant] = $this->authorizedBranchScope($branchId, 'inventory.view');
        [$variant, $identifier] = $this->variantForCode($tenant, $code);

        $response = $this->json([
            'code' => $identifier->code(),
            'action' => $identifier->action(),
            'variant' => [
                'id' => $variant->id(),
                'sku' => $variant->sku(),
                'name' => $variant->name(),
                'product_name' => $variant->product()->name(),
            ],
        ]);
        // Scanned inventory identities are tenant-scoped private responses.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route(
        '/api/v1/branches/{branchId}/inventory/labels/{code}',
        name: 'api_inventory_scan_label',
        methods: ['GET'],
    )]
    public function label(string $branchId, string $code): Response
    {
        [, $tenant] = $this->authorizedBranchScope($branchId, 'inventory.view');
        [$variant, $identifier] = $this->variantForCode($tenant, $code);

        return new Response(
            $this->labels->render(
                $tenant,
                $variant,
                $identifier,
                LabelTemplate::standard(),
            ),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /** @return array{0: ProductVariant, 1: ScanIdentifier} */
    private function variantForCode(Tenant $tenant, string $code): array
    {
        try {
            $canonical = ScanIdentifier::canonicalCode($code);
        } catch (DomainException) {
            throw new NotFoundHttpException('Código no encontrado.');
        }

        // Uniqueness on (tenant_id, sku) makes each tenant's SKU scan-stable.
        // The canonical scan domain currently supports only 4..64 byte SKUs.
        $variant = $this->entityManager
            ->getRepository(ProductVariant::class)
            ->findOneBy([
                'tenant' => $tenant,
                'sku' => $canonical,
                'active' => true,
            ]);

        if (!$variant instanceof ProductVariant || !$variant->product()->isActive()) {
            throw new NotFoundHttpException('Código no encontrado.');
        }

        $resolver = new ScanResolver();
        $resolver->register(ScanIdentifier::forVariant(
            $tenant,
            $variant,
            $canonical,
        ));
        $identifier = $resolver->resolve($tenant, $canonical);
        if (!$identifier instanceof ScanIdentifier
            || $identifier->entityId() !== $variant->id()) {
            throw new NotFoundHttpException('Código no encontrado.');
        }

        return [$variant, $identifier];
    }
}
