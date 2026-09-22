<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Identity\Entity\User;
use App\Domain\Observability\Entity\ErrorIncident;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminControllerTest extends WebTestCase
{
    /**
     * Regresión Issue #155: una AccessDeniedException de negocio (usuario
     * sin empresa activa) debía servirse como 403, no como 500 genérico.
     * ErrorIncidentSubscriber corría antes que el ExceptionListener de
     * seguridad de Symfony (prioridad 1) e interceptaba la excepción cruda,
     * bloqueando la conversión correcta.
     */
    public function testUserWithoutActiveTenantGetsForbiddenNotInternalError(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $before = $entityManager->getRepository(ErrorIncident::class)->count([]);

        $user = new User(
            'sin-empresa-'.bin2hex(random_bytes(4)).'@example.test',
            'Usuario Sin Empresa',
        );
        $entityManager->persist($user);
        $entityManager->flush();

        $client->loginUser($user);
        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);

        $after = $entityManager->getRepository(ErrorIncident::class)->count([]);
        self::assertSame(
            $before,
            $after,
            'Una denegación de acceso legítima no debe registrar un ErrorIncident.',
        );
    }
}
