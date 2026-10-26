<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Reports\SalesReportReader;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Branch;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

/** Read-only tenant reports, filtered by real orders.view branch permissions. */
final class SalesReportController extends AbstractController
{
    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly SalesReportReader $reader,
    ) {
    }

    #[Route('/api/reports/sales-by-product', name: 'api_sales_by_product', methods: ['GET'])]
    public function product(Request $request): JsonResponse
    {
        return $this->report($request, 'product');
    }

    #[Route('/api/reports/sales-by-month', name: 'api_sales_by_month', methods: ['GET'])]
    public function month(Request $request): JsonResponse
    {
        return $this->report($request, 'month');
    }

    private function report(Request $request, string $dimension): JsonResponse
    {
        $query = $request->query->all();
        if (array_diff(array_keys($query), ['from', 'to']) !== []
            || !is_string($query['from'] ?? null)
            || !is_string($query['to'] ?? null)) {
            throw new BadRequestHttpException('from y to son obligatorios (YYYY-MM-DD).');
        }
        try {
            [$start, $end] = SalesReportReader::window($query['from'], $query['to']);
        } catch (DomainException $exception) {
            throw new BadRequestHttpException('Rango de fechas inválido.', $exception);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Autenticación requerida.');
        }
        try {
            $tenant = $this->currentTenantForUser->resolve($user);
            $membership = $this->authorization->membership($user, $tenant);
            if (!$this->authorization->hasAnyPermission($user, $tenant, ['orders.view'])) {
                throw new AccessDeniedHttpException('No tienes permiso de reportes.');
            }
            $owner = $this->authorization->isOwner($membership);
            $allowedBranches = [];
            if (!$owner) {
                foreach ($this->entityManager->getRepository(Branch::class)
                    ->findBy(['tenant' => $tenant]) as $branch) {
                    if (in_array(
                        'orders.view',
                        $this->authorization->permissions($user, $tenant, $branch),
                        true,
                    )) {
                        $allowedBranches[$branch->id()] = true;
                    }
                }
                if ($allowedBranches === []) {
                    throw new AccessDeniedHttpException('No hay sedes autorizadas.');
                }
            }
        } catch (AccessDeniedException $exception) {
            throw new AccessDeniedHttpException('Acceso denegado.', $exception);
        }

        $sources = array_values(array_filter(
            $this->entityManager->getRepository(InventorySource::class)
                ->findBy(['tenant' => $tenant, 'active' => true]),
            static fn (InventorySource $source): bool => $owner
                || ($source->branch() !== null && isset($allowedBranches[$source->branch()->id()])),
        ));
        try {
            $result = $this->reader->read($tenant, $sources, $start, $end, $dimension);
        } catch (Throwable $exception) {
            // Do not expose ORM errors, source identifiers or database contents.
            throw new ServiceUnavailableHttpException(null, 'Reporte no disponible.', $exception);
        }

        return $this->json([
            'group_by' => $dimension,
            'from' => $query['from'],
            'to' => $query['to'],
            'items' => $result['items'],
            'currency_totals' => $result['currency_totals'],
            'rows_scanned' => $result['rows_scanned'],
        ], 200, ['Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff']);
    }
}
