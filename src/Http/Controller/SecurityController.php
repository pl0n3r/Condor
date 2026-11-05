<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Shared\Version\AppVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/admin/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils, AppVersion $version): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_admin');
        }

        $response = $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'app_version' => $version->human(),
        ]);
        // El formulario puede reflejar el último usuario o un error de acceso.
        // Evitar que navegadores y proxies conserven esos datos sensibles.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    #[Route('/admin/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Esta ruta es interceptada por el firewall de Symfony.');
    }
}
