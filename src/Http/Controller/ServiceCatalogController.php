<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Catalog\ServiceItemService;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Commerce\Entity\PriceList;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ServiceCatalogController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly ServiceItemService $services,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/catalog/services',
        name: 'api_catalog_services',
        methods: ['GET'],
    )]
    public function index(string $branchId): JsonResponse
    {
        [, $tenant] = $this->authorizedBranchScope($branchId, 'catalog.view');

        return $this->json(['services' => $this->services->listForTenant($tenant)]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/catalog/services',
        name: 'api_catalog_services_create',
        methods: ['POST'],
    )]
    public function create(string $branchId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            'catalog.create',
        );
        $payload = $this->payload(
            $request,
            [
                'name', 'slug', 'description', 'sku', 'price_list_id',
                'amount_minor', 'duration_minutes',
            ],
        );

        $priceListId = $this->requiredText($payload, 'price_list_id');
        $priceList = $this->entityManager->getRepository(PriceList::class)
            ->findOneBy([
                'id' => $priceListId,
                'tenant' => $tenant,
                'active' => true,
            ]);
        if (!$priceList instanceof PriceList) {
            throw new NotFoundHttpException('Lista de precios no encontrada en la empresa.');
        }

        $duration = $payload['duration_minutes'] ?? null;
        if ($duration !== null && !is_int($duration)) {
            throw new UnprocessableEntityHttpException(
                'La duración debe ser un número entero de minutos o null.',
            );
        }
        $description = $payload['description'] ?? null;
        if ($description !== null && !is_string($description)) {
            throw new UnprocessableEntityHttpException(
                'La descripción del servicio debe ser texto o null.',
            );
        }
        $amount = $payload['amount_minor'] ?? null;
        if (!is_int($amount)) {
            throw new UnprocessableEntityHttpException(
                'El precio del servicio debe expresarse en unidades menores enteras.',
            );
        }

        try {
            $item = $this->domain(fn (): array => $this->services->create(
                $tenant,
                $priceList,
                $this->requiredText($payload, 'name'),
                $this->requiredText($payload, 'slug'),
                $this->requiredText($payload, 'sku'),
                $amount,
                $duration,
                $description,
            ));
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictHttpException(
                'El slug o SKU ya está registrado en esta empresa.',
                $exception,
            );
        }

        $product = $item['product'];
        $this->audit(
            $tenant,
            $user,
            'catalog.service.created',
            Product::class,
            $product->id(),
            [
                'branch_id' => $branch->id(),
                'variant_id' => $item['variant']->id(),
                'price_list_id' => $priceList->id(),
            ],
        );
        $this->entityManager->flush();

        return $this->json([
            'service' => [
                'id' => $product->id(),
                'name' => $product->name(),
                'slug' => $product->slug(),
                'kind' => $product->kind(),
                'description' => $product->description(),
                'duration_minutes' => $product->durationMinutes(),
                'variant_id' => $item['variant']->id(),
                'sku' => $item['variant']->sku(),
                'price_list_id' => $priceList->id(),
                'amount_minor' => $item['price']->amountMinor(),
                'currency' => $priceList->currency(),
            ],
        ], Response::HTTP_CREATED);
    }

    /** @param array<string, mixed> $payload */
    private function requiredText(array $payload, string $name): string
    {
        $value = $payload[$name] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new UnprocessableEntityHttpException(
                sprintf('El campo %s requiere texto no vacío.', $name),
            );
        }

        return $value;
    }
}
