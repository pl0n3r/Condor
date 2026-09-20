<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use App\Shared\Version\AppVersion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SuperAdminController extends AbstractController
{
    #[Route('/superadmin', name: 'app_super_admin', methods: ['GET'])]
    public function __invoke(
        AppVersion $version,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyAccessUnlessGranted(User::ROLE_SUPER_ADMIN);

        return $this->render('superadmin/index.html.twig', [
            'app_version' => $version->human(),
            'tenant_count' => $entityManager->getRepository(Tenant::class)->count([]),
            'user_count' => $entityManager->getRepository(User::class)->count([]),
        ]);
    }
}
