<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Reporting\TenantCsvReportService;
use App\Application\Reporting\TenantReportRecordsReader;
use App\Domain\Inventory\Entity\InventorySource;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Read-only CSV transport; tenant and branch authority come from the session,
 * not query, headers or CSV field values.
 */
final class ReportsController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantReportRecordsReader $reader,
        private readonly TenantCsvReportService $csv,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/reports/{kind}.csv',
        name: 'api_branch_csv_reports',
        requirements: ['kind' => '[a-z]+'],
        methods: ['GET'],
    )]
    public function download(string $branchId, string $kind, Request $request): Response
    {
        // Unknown report categories must not silently fall back to another permission.
        $permission = match ($kind) {
            'inventory' => 'inventory.view',
            'sales' => 'orders.view',
            // Purchasing currently uses inventory.view, not a non-existent
            // "purchasing.view" permission.
            'purchases' => 'inventory.view',
            default => throw new NotFoundHttpException('Reporte no encontrado.'),
        };

        $params = $request->query->all();
        if (array_diff(array_keys($params), ['from', 'through']) !== []) {
            throw new BadRequestHttpException('Parámetros de reporte no permitidos.');
        }
        $from = $params['from'] ?? null;
        $through = $params['through'] ?? null;
        if (!is_string($from) || !is_string($through)) {
            throw new BadRequestHttpException('Rango de reporte requerido.');
        }

        // The branch is resolved inside the authenticated current tenant.
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, $permission);
        $source = $this->entityManager->getRepository(InventorySource::class)->findOneBy([
            'tenant' => $tenant,
            'branch' => $branch,
            'type' => InventorySource::TYPE_BRANCH,
        ]);
        if (!$source instanceof InventorySource || !$source->isActive()) {
            throw new NotFoundHttpException('Fuente de inventario de la sede no disponible.');
        }

        try {
            $records = $this->reader->read(
                $kind, $tenant, $source->legalEntity(), $source, $from, $through,
            );
            $csv = $this->csv->build($kind, $tenant->id(), $from, $through, $records);
        } catch (DomainException $exception) {
            if (in_array($exception->getMessage(), ['INVALID_REPORT_DATE', 'INVALID_REPORT_RANGE'], true)) {
                throw new BadRequestHttpException('Rango de fechas inválido.');
            }
            // Row budget, invalid persisted data and scope inconsistencies are
            // service failures, never partially downloaded CSV.
            throw new ServiceUnavailableHttpException(null, 'Reporte no disponible.');
        } catch (Throwable) {
            // Includes DB/ORM failures. No SQL, record content or credentials
            // may be exposed to the HTTP consumer.
            throw new ServiceUnavailableHttpException(null, 'Reporte no disponible.');
        }

        return new Response($csv['body'], Response::HTTP_OK, [
            'Content-Type' => $csv['content_type'],
            'Content-Disposition' => 'attachment; filename="'.$csv['filename'].'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
