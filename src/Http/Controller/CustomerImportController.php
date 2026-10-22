<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Commerce\CustomerCsvImporter;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** CSRF, sesión, sede y permiso customers.create en ambos pasos. */
final class CustomerImportController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly CustomerCsvImporter $importer,
    ) {
    }

    #[Route('/api/customers/import/preview', name: 'api_customers_import_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        [, $tenant, , $csv] = $this->validatedInput($request);

        return $this->json($this->domain(
            fn (): array => $this->importer->preview($tenant, $csv),
        ));
    }

    #[Route('/api/customers/import/commit', name: 'api_customers_import_commit', methods: ['POST'])]
    public function commit(Request $request): JsonResponse
    {
        [$actor, $tenant, $branch, $csv] = $this->validatedInput($request);
        try {
            return $this->json($this->domain(
                fn (): array => $this->importer->commit($tenant, $branch, $actor, $csv),
            ));
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictHttpException(
                'La importación cambió concurrentemente. Vuelve a generar la vista previa.',
                $exception,
            );
        }
    }

    /** @return array{0: User, 1: Tenant, 2: Branch, 3: string} */
    private function validatedInput(Request $request): array
    {
        $this->requireCsrf($request);
        $input = $this->payload($request, ['branch_id', 'csv']);
        $branchId = $this->requiredString($input, 'branch_id');
        $csv = $this->requiredString($input, 'csv');
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId, 'customers.create',
        );

        return [$user, $tenant, $branch, $csv];
    }

    /** @param array<string, mixed> $input */
    private function requiredString(array $input, string $field): string
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
