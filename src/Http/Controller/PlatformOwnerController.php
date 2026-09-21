<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Identity\Entity\User;
use App\Shared\Version\AppVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PlatformOwnerController extends AbstractController
{
    #[Route('/adminpl0n3r', name: 'app_platform_owner', methods: ['GET'])]
    public function __invoke(AppVersion $version): Response
    {
        $this->denyAccessUnlessGranted(User::ROLE_PLATFORM_OWNER);

        return $this->render('platform_owner/index.html.twig', [
            'app_version' => $version->human(),
        ]);
    }
}
