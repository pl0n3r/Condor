<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Identity\Entity\User;
use App\Shared\Version\AppVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AdminController extends AbstractController
{
    #[Route('/admin', name: 'app_admin', methods: ['GET'])]
    public function __invoke(
        AppVersion $version,
        CurrentTenantForUser $currentTenantForUser,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        if ($this->isGranted(User::ROLE_PLATFORM_OWNER)) {
            return $this->redirectToRoute('app_platform_owner');
        }

        $tenant = $currentTenantForUser->resolve($user);

        return $this->render('admin/index.html.twig', [
            'app_version' => $version->human(),
            'tenant_name' => $tenant->name(),
        ]);
    }
}
