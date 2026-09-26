<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use DomainException;
use JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

trait BranchApiSupport
{
    /**
     * @return array{0: User, 1: Tenant, 2: Branch, 3: Membership}
     */
    private function authorizedBranchScope(
        string $branchId,
        string $permission,
    ): array {
        [$user, $tenant, $branch] = $this->branchScope($branchId);
        $membership = $this->authorization->require(
            $user,
            $tenant,
            $branch,
            $permission,
        );

        return [$user, $tenant, $branch, $membership];
    }

    /** @return array{0: User, 1: Tenant, 2: Branch} */
    private function branchScope(string $branchId): array
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        try {
            $tenant = $this->currentTenantForUser->resolve($user);
        } catch (AccessDeniedException $exception) {
            throw new AccessDeniedHttpException(
                $exception->getMessage(),
                $exception,
            );
        }

        $branch = $this->entityManager->getRepository(Branch::class)->findOneBy([
            'id' => $branchId,
            'tenant' => $tenant,
        ]);
        if (!$branch instanceof Branch) {
            throw new NotFoundHttpException('Sede no encontrada.');
        }

        return [$user, $tenant, $branch];
    }

    private function requireCsrf(Request $request): void
    {
        $token = (string) $request->headers->get('X-CSRF-Token', '');
        if (!$this->isCsrfTokenValid('branch_access', $token)) {
            throw new AccessDeniedHttpException('Token CSRF inválido.');
        }
    }

    /**
     * @param list<string> $allowedFields
     * @return array<string, mixed>
     */
    private function payload(Request $request, array $allowedFields): array
    {
        try {
            $payload = json_decode(
                (string) $request->getContent(),
                false,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new BadRequestHttpException('JSON inválido.', $exception);
        }

        if (!is_object($payload)) {
            throw new BadRequestHttpException(
                'El cuerpo debe ser un objeto JSON.',
            );
        }

        $data = get_object_vars($payload);
        $unexpected = array_values(array_diff(
            array_keys($data),
            $allowedFields,
        ));
        if ($unexpected !== []) {
            throw new UnprocessableEntityHttpException(
                'El cuerpo contiene campos no permitidos.',
            );
        }

        return $data;
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function domain(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException(
                $exception->getMessage(),
                $exception,
            );
        }
    }

    /** @param array<string, mixed> $context */
    private function audit(
        Tenant $tenant,
        User $actor,
        string $action,
        string $entityType,
        string $entityId,
        array $context,
    ): void {
        $this->entityManager->persist(new AuditEvent(
            $tenant,
            $actor->id(),
            $action,
            $entityType,
            $entityId,
            $context,
        ));
    }
}
