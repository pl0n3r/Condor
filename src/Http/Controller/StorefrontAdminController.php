<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Storefront\StorefrontPresentation;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\StorefrontProfile;
use App\Domain\Organization\Entity\Tenant;
use App\Shared\Version\AppVersion;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class StorefrontAdminController extends AbstractController
{
    #[Route('/admin/storefront', name: 'app_storefront_admin', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        CurrentTenantForUser $currentTenantForUser,
        BranchAuthorization $authorization,
        EntityManagerInterface $entityManager,
        StorefrontPresentation $storefront,
        AppVersion $version,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isActive()) {
            throw new AccessDeniedHttpException();
        }

        try {
            $tenant = $currentTenantForUser->resolve($user);
            $membership = $authorization->membership($user, $tenant);
        } catch (AccessDeniedException $exception) {
            throw new AccessDeniedHttpException('No tienes acceso a esta empresa.', $exception);
        }

        $canView = $membership->roleKey() === Membership::ROLE_OWNER;
        $canEdit = $canView;

        if (!$canView) {
            $branches = $entityManager->getRepository(Branch::class)->findBy(['tenant' => $tenant]);
            foreach ($branches as $branch) {
                if (!$branch instanceof Branch) {
                    continue;
                }
                $permissions = $authorization->permissions($user, $tenant, $branch);
                $canView = $canView || in_array('site.view', $permissions, true);
                $canEdit = $canEdit || in_array('site.update', $permissions, true);
            }
        }

        if (!$canView && !$canEdit) {
            throw new AccessDeniedHttpException('No tienes permiso para consultar este sitio.');
        }

        $profile = $entityManager->getRepository(StorefrontProfile::class)
            ->findOneBy(['tenant' => $tenant]);
        $identity = $storefront->publicIdentity($tenant);
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$canEdit) {
                throw new AccessDeniedHttpException('No tienes permiso para editar este sitio.');
            }
            if (!$this->isCsrfTokenValid(
                'storefront_profile',
                (string) $request->request->get('_token', ''),
            )) {
                throw new AccessDeniedHttpException('La solicitud de edición es inválida.');
            }

            $submitted = $request->request->all();
            $unexpected = array_diff(array_keys($submitted), ['_token', 'headline', 'description']);
            if ($unexpected !== []
                || !is_string($submitted['headline'] ?? null)
                || !is_string($submitted['description'] ?? null)) {
                $errors[] = 'El formulario contiene campos inválidos.';
            } else {
                $identity['headline'] = trim($submitted['headline']);
                $identity['description'] = trim($submitted['description']);
                try {
                    if ($errors === []) {
                        if ($profile instanceof StorefrontProfile) {
                            $profile->update($identity['headline'], $identity['description']);
                        } else {
                            $profile = new StorefrontProfile(
                                $tenant,
                                $identity['headline'],
                                $identity['description'],
                            );
                            $entityManager->persist($profile);
                        }
                        $entityManager->flush();

                        return $this->redirectToRoute('app_storefront_admin', ['saved' => '1']);
                    }
                } catch (DomainException) {
                    $errors[] = 'El titular admite hasta 120 caracteres y la descripción hasta 500.';
                }
            }
        }

        return $this->render('tenant/manage.html.twig', [
            'app_version' => $version->human(),
            'storefront' => $identity,
            'domains' => $storefront->registeredDomains($tenant),
            'can_edit' => $canEdit,
            'saved' => $request->query->get('saved') === '1',
            'errors' => $errors,
        ], new Response(status: $errors === [] ? 200 : 422));
    }
}
