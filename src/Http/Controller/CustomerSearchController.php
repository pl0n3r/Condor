<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Commerce\CustomerSearch;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Commerce\Entity\Customer;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Adaptador HTTP de solo lectura. La búsqueda y sus segmentos pertenecen a #852. */
final class CustomerSearchController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly CustomerSearch $customerSearch,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/customers/search',
        name: 'api_customers_search',
        methods: ['GET'],
    )]
    public function search(string $branchId, Request $request): JsonResponse
    {
        // Autenticar y autorizar la sede ANTES de consultar clientes.
        [, $tenant] = $this->authorizedBranchScope($branchId, 'customers.view');
        $filters = $request->query->all();
        foreach ($filters as $key => $value) {
            if (!in_array($key, ['term', 'segment'], true) || !is_string($value)) {
                throw new BadRequestHttpException('Filtros de clientes no permitidos.');
            }
        }

        $term = $filters['term'] ?? '';
        $segment = $filters['segment'] ?? CustomerSearch::SEGMENT_ALL;
        if (!mb_check_encoding($term, 'UTF-8')
            || mb_strlen($term, 'UTF-8') > 120
            || preg_match('/[\x00-\x1F\x7F]/u', $term) === 1
            || !in_array($segment, [
                CustomerSearch::SEGMENT_ALL,
                CustomerSearch::SEGMENT_NEW,
                CustomerSearch::SEGMENT_INACTIVE,
                CustomerSearch::SEGMENT_TOP,
            ], true)) {
            throw new BadRequestHttpException('Filtro o segmento de clientes inválido.');
        }

        try {
            $customers = $this->customerSearch->search($tenant, $term, $segment);
        } catch (DomainException $exception) {
            throw new BadRequestHttpException('Filtro de clientes inválido.', $exception);
        }

        return new JsonResponse([
            'customers' => array_map(
                static fn (Customer $customer): array => [
                    'id' => $customer->id(),
                    'name' => $customer->name(),
                    'email' => $customer->email(),
                    'phone' => $customer->phone(),
                    'notes' => $customer->notes(),
                    'commercial_category_id' => $customer->commercialCategory()?->id(),
                ],
                $customers,
            ),
        ], headers: ['Cache-Control' => 'private, no-store']);
    }
}
