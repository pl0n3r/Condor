<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Inventory\InventoryConflictException;
use App\Application\Inventory\StockCsvAdjuster;
use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

/** Strict JSON + CSRF transport. No client-supplied tenant or permission. */
final class InventoryCsvAdjustController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly StockCsvAdjuster $adjuster,
    ) {
    }

    #[Route('/api/inventory/adjust-csv/preview', name: 'api_inventory_csv_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        return $this->handle($request, false);
    }

    #[Route('/api/inventory/adjust-csv/commit', name: 'api_inventory_csv_commit', methods: ['POST'])]
    public function commit(Request $request): JsonResponse
    {
        return $this->handle($request, true);
    }

    private function handle(Request $request, bool $commit): JsonResponse
    {
        $this->requireCsrf($request);
        $payload = $this->payload($request, ['csv']);
        $csv = $payload['csv'] ?? null;
        if (!is_string($csv)) {
            throw new UnprocessableEntityHttpException('El archivo CSV es obligatorio.');
        }

        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw new AccessDeniedHttpException('Autenticación requerida.');
        }
        try {
            $tenant = $this->currentTenantForUser->resolve($actor);
            if (!$this->authorization->hasAnyPermission($actor, $tenant, ['inventory.update'])) {
                throw new AccessDeniedHttpException('No tienes permiso para ajustar inventario.');
            }
        } catch (AccessDeniedException $exception) {
            throw new AccessDeniedHttpException('No tienes permiso sobre el tenant.', $exception);
        }

        try {
            $data = $this->adjuster->process($tenant, $actor, $csv, $commit);
        } catch (InventoryConflictException $exception) {
            throw new ConflictHttpException('El conteo entró en conflicto con otra operación.', $exception);
        } catch (DomainException $exception) {
            if (str_starts_with($exception->getMessage(), 'CSV_')
                && in_array($exception->getMessage(), [
                    'CSV_INVALID_OR_TOO_LARGE', 'CSV_HEADER_INVALID',
                    'CSV_ROW_LIMIT', 'CSV_EMPTY',
                ], true)) {
                throw new BadRequestHttpException('Archivo CSV inválido.', $exception);
            }
            throw new ConflictHttpException('El inventario cambió durante la operación.', $exception);
        } catch (Throwable $exception) {
            throw new ServiceUnavailableHttpException(null, 'Ajuste CSV no disponible.', $exception);
        }

        return $this->json($data, 200, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
