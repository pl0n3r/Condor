<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Catalog\ProductCsvImporter;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Importación explícita: primero preview sin escrituras, luego commit con CSRF. */
final class CatalogImportController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductCsvImporter $importer,
    ) {
    }

    #[Route('/api/catalog/import/preview', name: 'api_catalog_import_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        $input = $this->payload($request, ['branch_id', 'csv']);
        [$tenant, $csv] = $this->scopeInput($input);

        return $this->json($this->domain(
            fn (): array => $this->importer->preview($tenant, $csv),
        ));
    }

    #[Route('/api/catalog/import/commit', name: 'api_catalog_import_commit', methods: ['POST'])]
    public function commit(Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        $input = $this->payload($request, ['branch_id', 'csv']);
        $branchId = $this->requiredInput($input, 'branch_id');
        $csv = $this->requiredInput($input, 'csv');

        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId, 'catalog.create',
        );
        try {
            return $this->json($this->domain(
                fn (): array => $this->importer->commit($tenant, $branch, $user, $csv),
            ));
        } catch (UniqueConstraintViolationException $exception) {
            // La restricción UNIQUE por tenant/sku también protege carreras entre commits.
            throw new ConflictHttpException(
                'Un SKU o slug fue modificado durante la importación; vuelve a generar la vista previa.',
                $exception,
            );
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: \App\Domain\Organization\Entity\Tenant, 1: string}
     */
    private function scopeInput(array $input): array
    {
        $branchId = $this->requiredInput($input, 'branch_id');
        $csv = $this->requiredInput($input, 'csv');
        [, $tenant] = $this->authorizedBranchScope(
            $branchId, 'catalog.create',
        );

        return [$tenant, $csv];
    }

    /** @param array<string, mixed> $input */
    private function requiredInput(array $input, string $field): string
    {
        $value = $input[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new UnprocessableEntityHttpException(
                sprintf('El campo %s es obligatorio y debe ser texto.', $field),
            );
        }

        return $value;
    }
}
