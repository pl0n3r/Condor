<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Admin\GlobalSearch;
use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Branch;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Private administrative search. No tenant ID, permission or freshness input
 * may be taken from the query string: all authority is server-side.
 */
final class AdminSearchController extends AbstractController
{
    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly GlobalSearch $search,
    ) {
    }

    #[Route('/api/admin/search', name: 'api_admin_global_search', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('Autenticación requerida.');
        }

        try {
            $tenant = $this->currentTenantForUser->resolve($user);
        } catch (AccessDeniedException $exception) {
            throw new AccessDeniedHttpException(
                'No tienes acceso a una empresa activa.',
                $exception,
            );
        }

        // A single authenticated session may make at most 30 searches/minute.
        // Only integer counters are retained: never the query, tenant or PII.
        $this->limitSession($request);

        $raw = $request->query->get('q');
        if (!is_string($raw)) {
            throw new BadRequestHttpException('La búsqueda debe ser texto.');
        }
        $query = trim($raw);
        if (
            mb_strlen($query, 'UTF-8') < 2
            || mb_strlen($query, 'UTF-8') > 64
            || preg_match('/^[\p{L}\p{N} .-]+$/uD', $query) !== 1
        ) {
            throw new BadRequestHttpException(
                'La búsqueda admite entre 2 y 64 caracteres de texto.',
            );
        }

        $permissions = [
            'products' => $this->authorization->hasAnyPermission(
                $user,
                $tenant,
                ['catalog.view'],
            ),
            'customers' => $this->authorization->hasAnyPermission(
                $user,
                $tenant,
                ['customers.view'],
            ),
            'orders' => $this->authorization->hasAnyPermission(
                $user,
                $tenant,
                ['orders.view'],
            ),
        ];

        // Orders also belong to a branch's inventory source. An orders.view
        // grant in one branch must not reveal another branch's orders.
        $sources = [];
        if ($permissions['orders']) {
            // Owner access includes active logical inventory sources (no branch).
            // Staff access remains restricted to permitted physical branches.
            $owner = $this->authorization->isOwner(
                $this->authorization->membership($user, $tenant),
            );
            $allowedBranches = [];
            foreach (
                $this->entityManager->getRepository(Branch::class)
                    ->findBy(['tenant' => $tenant]) as $branch
            ) {
                if (
                    in_array(
                        'orders.view',
                        $this->authorization->permissions($user, $tenant, $branch),
                        true,
                    )
                ) {
                    $allowedBranches[] = $branch;
                }
            }
            if ($owner || $allowedBranches !== []) {
                // The owner can search logical and branch-backed orders alike.
                // Every other role receives only sources from allowed branches.
                $criteria = ['tenant' => $tenant, 'active' => true];
                if (!$owner) {
                    $criteria['branch'] = $allowedBranches;
                }
                foreach (
                    $this->entityManager->getRepository(InventorySource::class)
                        ->findBy($criteria) as $source
                ) {
                    $sources[] = $source;
                }
            }
        }

        $response = new JsonResponse([
            'results' => $this->search->find(
                $tenant,
                $query,
                $permissions,
                $sources,
            ),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function limitSession(Request $request): void
    {
        if (!$request->hasSession()) {
            throw new AccessDeniedHttpException('Sesión requerida.');
        }
        $session = $request->getSession();
        $now = time();
        $start = (int) $session->get('admin_search_window_start', 0);
        $count = (int) $session->get('admin_search_window_count', 0);
        if ($start <= 0 || $start > $now || $now - $start >= 60) {
            $start = $now;
            $count = 0;
        }
        if ($count >= 30) {
            throw new TooManyRequestsHttpException(
                max(1, 60 - ($now - $start)),
                'Demasiadas búsquedas. Inténtalo más tarde.',
            );
        }
        $session->set('admin_search_window_start', $start);
        $session->set('admin_search_window_count', $count + 1);
    }
}
