<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Inventory\RestockSuggester;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventoryMovement;
use App\Domain\Inventory\Entity\InventorySource;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/** Read-only inventory demand estimates, always scoped to an authorized branch. */
final class RestockSuggestionsController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly RestockSuggester $suggester,
    ) {
    }

    #[Route('/api/inventory/restock-suggestions', name: 'api_inventory_restock_suggestions', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $query = $request->query->all();
        if (array_keys($query) !== ['branch_id']
            || !is_string($query['branch_id'])
            || trim($query['branch_id']) === '') {
            throw new BadRequestHttpException('Se requiere únicamente branch_id.');
        }
        [, $tenant, $branch] = $this->authorizedBranchScope($query['branch_id'], 'inventory.view');
        $source = $this->entityManager->getRepository(InventorySource::class)->findOneBy([
            'tenant' => $tenant,
            'legalEntity' => $branch->legalEntity(),
            'branch' => $branch,
            'type' => InventorySource::TYPE_BRANCH,
            'active' => true,
        ]);
        if (!$source instanceof InventorySource) {
            throw new NotFoundHttpException('Fuente de inventario activa no encontrada.');
        }

        try {
            $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->sub(new DateInterval('P30D'));
            // Only real order consumption is demand. Transfers/adjustments are not sales.
            $rows = $this->entityManager->createQueryBuilder()
                ->select('IDENTITY(m.variant) AS variantId', 'SUM(m.delta) AS totalDelta')
                ->from(InventoryMovement::class, 'm')
                ->where('m.tenant = :tenant')
                ->andWhere('m.source = :source')
                ->andWhere('m.type = :kind')
                ->andWhere('m.createdAt >= :since')
                ->setParameter('tenant', $tenant)
                ->setParameter('source', $source)
                ->setParameter('kind', InventoryMovement::TYPE_ORDER_CONSUMPTION)
                ->setParameter('since', $since)
                ->groupBy('m.variant')
                ->getQuery()->getArrayResult();

            $demand = [];
            foreach ($rows as $row) {
                $sum = (string) ($row['totalDelta'] ?? '');
                // Prevent corrupt/overflowing aggregates from silently suggesting stock.
                if (preg_match('/^-[1-9][0-9]*$/D', $sum) !== 1
                    || strlen($sum) > 11
                    || (int) substr($sum, 1) > 2147483647) {
                    throw new \UnexpectedValueException('Consumo agregado inválido.');
                }
                $demand[(string) $row['variantId']] = -(int) $sum;
            }

            $items = [];
            foreach ($this->entityManager->getRepository(InventoryBalance::class)
                ->findBy(['tenant' => $tenant, 'source' => $source]) as $balance) {
                if (!$balance instanceof InventoryBalance) {
                    continue;
                }
                $variant = $balance->variant();
                if (!$variant->isActive() || !$variant->product()->isActive()) {
                    continue;
                }
                $estimate = $this->suggester->suggest(
                    $demand[$variant->id()] ?? 0,
                    $balance->quantity(),
                    $balance->reservedQuantity(),
                );
                if ($estimate !== null) {
                    $items[] = [
                        'variant_id' => $variant->id(),
                        'sku' => $variant->sku(),
                        'source_id' => $source->id(),
                        ...$estimate,
                    ];
                }
            }
            usort($items, static fn (array $a, array $b): int =>
                [$a['sku'], $a['variant_id']] <=> [$b['sku'], $b['variant_id']]);
        } catch (Throwable $exception) {
            throw new ServiceUnavailableHttpException(null, 'No fue posible calcular el resurtido.', $exception);
        }

        return $this->json([
            'branch_id' => $branch->id(), 'source_id' => $source->id(),
            'window_days' => 30, 'suggestions' => $items,
        ], 200, ['Cache-Control' => 'private, no-store, max-age=0']);
    }
}
