<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Shared\Version\AppVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(AppVersion $version): Response
    {
        return $this->render('home/index.html.twig', [
            'app_version' => $version->human(),
        ]);
    }
}
