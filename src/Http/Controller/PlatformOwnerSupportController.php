<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Platform\OwnerSupportSession;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PlatformOwnerSupportController extends AbstractController
{
    public function __construct(
        private readonly OwnerSupportSession $support,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/adminpl0n3r/api/support', name: 'platform_owner_support_start', methods: ['POST'])]
    public function start(Request $request): JsonResponse
    {
        $actor = $this->ownerAndCsrf($request);
        $input = json_decode((string) $request->getContent(), true);
        if (!is_array($input) || !is_string($input['tenant_id'] ?? null)) {
            throw new BadRequestHttpException('Debes elegir una empresa válida.');
        }

        $tenant = $this->entityManager->getRepository(Tenant::class)
            ->find($input['tenant_id']);
        if (!$tenant instanceof Tenant) {
            throw new NotFoundHttpException('Empresa no encontrada.');
        }

        $branch = null;
        if (array_key_exists('branch_id', $input) && $input['branch_id'] !== null) {
            if (!is_string($input['branch_id'])) {
                throw new BadRequestHttpException('Identificador de sede inválido.');
            }
            $branch = $this->entityManager->getRepository(Branch::class)->findOneBy([
                'id' => $input['branch_id'],
                'tenant' => $tenant,
            ]);
            if (!$branch instanceof Branch) {
                throw new NotFoundHttpException('La sede no pertenece a la empresa.');
            }
        }

        $module = $input['module'] ?? '';
        if (
            !is_string($module)
            || ($module !== '' && preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $module) !== 1)
        ) {
            throw new BadRequestHttpException('Módulo inválido.');
        }

        $this->support->start($actor, $tenant, $branch);

        return $this->json([
            'ok' => true,
            'href' => '/admin'.($module !== '' ? '#'.$module : ''),
        ], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/adminpl0n3r/api/support/close', name: 'platform_owner_support_close', methods: ['POST'])]
    public function close(Request $request): JsonResponse
    {
        $this->support->stop($this->ownerAndCsrf($request));

        return $this->json([
            'ok' => true,
            'href' => '/adminpl0n3r',
        ], headers: ['Cache-Control' => 'no-store']);
    }

    private function ownerAndCsrf(Request $request): User
    {
        $actor = $this->getUser();
        if (
            !$actor instanceof User
            || !$actor->isActive()
            || !$actor->hasRole(User::ROLE_PLATFORM_OWNER)
        ) {
            throw new AccessDeniedHttpException();
        }
        if (!$this->isCsrfTokenValid(
            'platform_tenant_management',
            (string) $request->headers->get('X-CSRF-Token', ''),
        )) {
            throw new AccessDeniedHttpException('Token CSRF inválido.');
        }

        return $actor;
    }
}
