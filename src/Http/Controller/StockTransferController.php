<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Inventory\InventoryConflictException;
use App\Application\Inventory\StockTransferService;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Inventory\Entity\InventoryTransfer;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\LegalEntity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Strict physical branch transfers. The legacy generic /transfers API remains
 * unchanged for its existing callers and logical inventory sources.
 */
final class StockTransferController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly StockTransferService $transfers,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/inventory/branch-transfers',
        name: 'api_inventory_branch_transfer_create',
        methods: ['POST'],
    )]
    public function create(string $branchId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [$user, $tenant, $branch, $membership] = $this->authorizedBranchScope(
            $branchId,
            'inventory.update',
        );
        $legalEntity = $branch->legalEntity();
        if (!$legalEntity instanceof LegalEntity) {
            throw new ConflictHttpException('La sede no tiene entidad legal.');
        }

        $data = $this->payload($request, [
            'source_from_id', 'source_to_id', 'variant_id',
            'quantity', 'idempotency_key',
        ]);

        $from = $this->source($data, 'source_from_id', $tenant, $legalEntity);
        $to = $this->source($data, 'source_to_id', $tenant, $legalEntity);

        if ($from->branch()?->id() !== $branch->id()) {
            throw new AccessDeniedHttpException('La sede de origen no corresponde a la ruta.');
        }
        if (!$this->authorization->isOwner($membership)) {
            foreach ([$from, $to] as $source) {
                try {
                    $this->authorization->require(
                        $user,
                        $tenant,
                        $source->branch(),
                        'inventory.update',
                    );
                } catch (AccessDeniedException $exception) {
                    throw new AccessDeniedHttpException('No tienes permiso en ambas sedes.', $exception);
                }
            }
        }

        $variantId = $this->text($data, 'variant_id');
        $variant = $this->entityManager->getRepository(ProductVariant::class)->findOneBy([
            'id' => $variantId,
            'tenant' => $tenant,
            'active' => true,
        ]);
        if (!$variant instanceof ProductVariant || !$variant->product()->isActive()) {
            throw new NotFoundHttpException('Variante no encontrada.');
        }
        if (!is_int($data['quantity'] ?? null)) {
            throw new UnprocessableEntityHttpException('La cantidad debe ser un entero.');
        }

        $created = false;
        try {
            $transfer = $this->domain(function () use (
                $tenant, $variant, $from, $to, $user, $data,
                $branch, &$created,
            ): InventoryTransfer {
                return $this->transfers->transfer(
                    $tenant,
                    $variant,
                    $from,
                    $to,
                    $data['quantity'],
                    $user->id(),
                    $this->text($data, 'idempotency_key'),
                    function (InventoryTransfer $new) use (
                        $tenant, $user, $branch, $from, $to, $variant, &$created,
                    ): void {
                        $created = true;
                        $this->audit(
                            $tenant,
                            $user,
                            'inventory.transferred',
                            InventoryTransfer::class,
                            $new->id(),
                            [
                                'branch_id' => $branch->id(),
                                'source_from_id' => $from->id(),
                                'source_to_id' => $to->id(),
                                'variant_id' => $variant->id(),
                                'quantity' => $new->quantity(),
                            ],
                        );
                    },
                );
            });
        } catch (InventoryConflictException $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        }

        $response = $this->json([
            'transfer' => [
                'id' => $transfer->id(),
                'source_from_id' => $transfer->sourceFrom()->id(),
                'source_to_id' => $transfer->sourceTo()->id(),
                'variant_id' => $transfer->variant()->id(),
                'quantity' => $transfer->quantity(),
                'status' => $transfer->status(),
            ],
        ], $created ? Response::HTTP_CREATED : Response::HTTP_OK);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** @param array<string, mixed> $data */
    private function source(
        array $data,
        string $field,
        Tenant $tenant,
        LegalEntity $legalEntity,
    ): InventorySource {
        $source = $this->entityManager->getRepository(InventorySource::class)->findOneBy([
            'id' => $this->text($data, $field),
            'tenant' => $tenant,
            'legalEntity' => $legalEntity,
            'type' => InventorySource::TYPE_BRANCH,
            'active' => true,
        ]);
        if (!$source instanceof InventorySource || $source->branch() === null) {
            throw new NotFoundHttpException('Fuente de sede no encontrada.');
        }

        return $source;
    }

    /** @param array<string, mixed> $data */
    private function text(array $data, string $field): string
    {
        $value = $data[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new UnprocessableEntityHttpException('Campo obligatorio inválido: '.$field);
        }

        return trim($value);
    }
}
