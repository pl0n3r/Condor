<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Infrastructure\Http\ApiErrorResponseFactory;
use App\Shared\Version\AppVersion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ApiContextController extends AbstractController
{
    #[Route('/api/v1/context', name: 'api_context', methods: ['GET'])]
    public function __invoke(
        Request $request,
        CurrentTenantForUser $currentTenantForUser,
        BranchAuthorization $authorization,
        EntityManagerInterface $entityManager,
        AppVersion $version,
        ApiErrorResponseFactory $apiErrors,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        try {
            $tenant = $currentTenantForUser->resolve($user);
        } catch (AccessDeniedException $exception) {
            throw new AccessDeniedHttpException(
                $exception->getMessage(),
                $exception,
            );
        }

        $branches = array_values(array_filter(
            $entityManager->getRepository(Branch::class)->findBy([
                'tenant' => $tenant,
            ]),
            static fn (mixed $branch): bool => $branch instanceof Branch,
        ));
        usort(
            $branches,
            static fn (Branch $left, Branch $right): int => (
                ($right->isDefault() <=> $left->isDefault())
                ?: strcmp($left->name(), $right->name())
            ),
        );

        $branches = array_values(array_filter(
            $branches,
            fn (Branch $branch): bool => $authorization->canAccessBranch(
                $user,
                $tenant,
                $branch,
            ),
        ));

        if ($branches === []) {
            return $apiErrors->create(
                $request,
                'branch_scope_required',
                'No tienes una sede asignada en esta empresa.',
                Response::HTTP_FORBIDDEN,
                [
                    'tenant' => [
                        'id' => $tenant->id(),
                        'name' => $tenant->name(),
                        'slug' => $tenant->slug(),
                    ],
                ],
            );
        }

        $requestedBranch = trim(
            (string) $request->query->get('branch', ''),
        );
        $activeBranch = $branches[0];

        if ($requestedBranch !== '') {
            $match = array_values(array_filter(
                $branches,
                static fn (Branch $branch): bool => (
                    $branch->id() === $requestedBranch
                ),
            ));
            if ($match === []) {
                throw new AccessDeniedHttpException(
                    'No tienes acceso a la sede solicitada.',
                );
            }
            $activeBranch = $match[0];
        }

        return $this->json([
            'tenant' => [
                'id' => $tenant->id(),
                'name' => $tenant->name(),
                'slug' => $tenant->slug(),
            ],
            'branches' => array_map(
                static fn (Branch $branch): array => [
                    'id' => $branch->id(),
                    'name' => $branch->name(),
                    'slug' => $branch->slug(),
                    'is_default' => $branch->isDefault(),
                ],
                $branches,
            ),
            'active_branch' => [
                'id' => $activeBranch->id(),
                'name' => $activeBranch->name(),
                'slug' => $activeBranch->slug(),
                'is_default' => $activeBranch->isDefault(),
            ],
            'permissions' => $authorization->permissions(
                $user,
                $tenant,
                $activeBranch,
            ),
            'version' => $version->human(),
        ]);
    }
}
