<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Inventory\InventoryConflictException;
use App\Application\Inventory\TransferProposalSuggester;
use App\Application\Inventory\StockTransferService;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventoryMovement;
use App\Domain\Inventory\Entity\InventoryTransfer;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Branch;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
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
        private readonly TransferProposalSuggester $proposalSuggester,
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
                $sourceBranch = $source->branch();
                if (!$sourceBranch instanceof Branch) {
                    throw new NotFoundHttpException('La fuente no está vinculada a una sede.');
                }
                try {
                    $this->authorization->require(
                        $user,
                        $tenant,
                        $sourceBranch,
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

    #[Route(
        '/api/v1/branches/{branchId}/inventory/branch-transfers/proposals',
        name: 'api_inventory_branch_transfer_proposals',
        methods: ['GET'],
    )]
    public function proposals(string $branchId): JsonResponse
    {
        [$user, $tenant, $branch, $membership] = $this->authorizedBranchScope(
            $branchId,
            'inventory.update',
        );
        $legalEntity = $branch->legalEntity();
        if (!$legalEntity instanceof LegalEntity) {
            throw new ConflictHttpException('La sede no tiene entidad legal.');
        }

        // The active tenant and legal entity constrain all source, balance and
        // consumption reads. Inaccessible branches are excluded before lookup.
        $sources = $this->entityManager->getRepository(InventorySource::class)->findBy([
            'tenant' => $tenant,
            'legalEntity' => $legalEntity,
            'type' => InventorySource::TYPE_BRANCH,
            'active' => true,
        ]);
        $destination = null;
        $donors = [];
        foreach ($sources as $source) {
            if (!$source instanceof InventorySource
                || !$source->branch() instanceof Branch
                || !$source->branch()->isActive()) {
                continue;
            }
            if ($source->branch()->id() === $branchId) {
                $destination = $source;
                continue;
            }
            if (!$this->authorization->isOwner($membership)) {
                try {
                    $this->authorization->require(
                        $user,
                        $tenant,
                        $source->branch(),
                        'inventory.update',
                    );
                } catch (AccessDeniedException) {
                    continue;
                }
            }
            $donors[] = $source;
        }

        if (!$destination instanceof InventorySource || $donors === []) {
            return $this->json(
                ['branch_id' => $branchId, 'proposals' => []],
                Response::HTTP_OK,
                ['Cache-Control' => 'private, no-store, max-age=0'],
            );
        }

        $visibleSources = [$destination, ...$donors];
        $stocks = [];
        foreach ($this->entityManager->getRepository(InventoryBalance::class)->findBy([
            'tenant' => $tenant,
            'legalEntity' => $legalEntity,
            'source' => $visibleSources,
        ]) as $balance) {
            if (!$balance instanceof InventoryBalance) {
                continue;
            }
            $stocks[$balance->source()->id()][$balance->variant()->id()] = $balance;
        }

        $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->sub(new DateInterval('P30D'));
        $rows = $this->entityManager->createQueryBuilder()
            ->select(
                'IDENTITY(m.source) AS source_id',
                'IDENTITY(m.variant) AS variant_id',
                'SUM(m.delta) AS total_delta',
            )
            ->from(InventoryMovement::class, 'm')
            ->where('m.tenant = :tenant')
            ->andWhere('m.legalEntity = :legalEntity')
            ->andWhere('m.source IN (:sources)')
            ->andWhere('m.type = :kind')
            ->andWhere('m.createdAt >= :since')
            ->setParameter('tenant', $tenant)
            ->setParameter('legalEntity', $legalEntity)
            ->setParameter('sources', $visibleSources)
            ->setParameter('kind', InventoryMovement::TYPE_ORDER_CONSUMPTION)
            ->setParameter('since', $since)
            ->groupBy('m.source')
            ->addGroupBy('m.variant')
            ->getQuery()->getArrayResult();

        $demand = [];
        foreach ($rows as $row) {
            $sum = (string) ($row['total_delta'] ?? '');
            if (preg_match('/^-[1-9][0-9]*$/D', $sum) !== 1
                || strlen($sum) > 11
                || (int) substr($sum, 1) > 2147483647) {
                throw new ServiceUnavailableHttpException(
                    null,
                    'No fue posible calcular el consumo de las sedes.',
                );
            }
            $demand[(string) $row['source_id']][(string) $row['variant_id']] = -(int) $sum;
        }

        $items = [];
        $targetId = $destination->id();
        foreach ($this->entityManager->getRepository(ProductVariant::class)->findBy([
            'tenant' => $tenant,
            'active' => true,
        ]) as $variant) {
            if (!$variant instanceof ProductVariant || !$variant->product()->isActive()) {
                continue;
            }
            $variantId = $variant->id();
            $needed = $demand[$targetId][$variantId] ?? 0;
            if ($needed <= 0) {
                continue;
            }
            $targetBalance = $stocks[$targetId][$variantId] ?? null;
            $best = null;
            foreach ($donors as $donor) {
                $donorBalance = $stocks[$donor->id()][$variantId] ?? null;
                if (!$donorBalance instanceof InventoryBalance) {
                    continue;
                }
                $proposal = $this->proposalSuggester->suggest(
                    $needed,
                    $targetBalance instanceof InventoryBalance ? $targetBalance->quantity() : 0,
                    $targetBalance instanceof InventoryBalance ? $targetBalance->reservedQuantity() : 0,
                    $demand[$donor->id()][$variantId] ?? 0,
                    $donorBalance->quantity(),
                    $donorBalance->reservedQuantity(),
                );
                if ($proposal === null) {
                    continue;
                }
                if ($best === null || $proposal['origin_excess'] > $best['origin_excess']) {
                    $best = [
                        'source_from_id' => $donor->id(),
                        'source_from_branch_id' => $donor->branch()->id(),
                        'source_from_name' => $donor->name(),
                        'source_to_id' => $targetId,
                        'source_to_branch_id' => $branchId,
                        'variant_id' => $variantId,
                        'sku' => $variant->sku(),
                        ...$proposal,
                    ];
                }
            }
            if ($best !== null) {
                $items[] = $best;
            }
        }
        usort($items, static fn (array $a, array $b): int =>
            [$a['sku'], $a['variant_id']] <=> [$b['sku'], $b['variant_id']]);

        return $this->json(
            ['branch_id' => $branchId, 'window_days' => 30, 'proposals' => $items],
            Response::HTTP_OK,
            ['Cache-Control' => 'private, no-store, max-age=0'],
        );
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
