<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * #779 AC-01: sesiones invalidadas y tokens CSRF falsos deben impedir
 * mutaciones en el firewall real; fixtures de dos tenants en MariaDB CI.
 */
final class SecurityHttpSessionNegativesTest extends WebTestCase
{
    public function testExpiredSessionsAndInvalidCsrfOnMutatingRoutes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $suffix = bin2hex(random_bytes(5));
        $tenantA = new Tenant('Seguridad A '.$suffix, 'security-a-'.$suffix);
        $tenantB = new Tenant('Seguridad B '.$suffix, 'security-b-'.$suffix);
        $userA = new User('security-a-'.$suffix.'@example.test', 'Tenant A');
        $userB = new User('security-b-'.$suffix.'@example.test', 'Tenant B');
        $membershipA = new Membership($tenantA, $userA, Membership::ROLE_OWNER);
        $membershipB = new Membership($tenantB, $userB, Membership::ROLE_OWNER);

        $passwords = [];
        foreach ([$userA, $userB] as $user) {
            // El loginUser de Symfony necesita cuentas persistidas, no credenciales.
            $user->setPasswordHash('$2y$13$zFPXw3t1Q7K4MZbNtxPWQ.5gkXQa32rEjVpz51GZaOGQ58sv9EfL2');
            $passwords[$user->id()] = $user->getPassword();
        }

        foreach ([$tenantA, $tenantB, $userA, $userB, $membershipA, $membershipB] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $connection = $em->getConnection();
        $initialCount = $em->getRepository(Membership::class)->count([]);

        foreach ([$userA, $userB] as $user) {
            $client->loginUser($user);

            // Control positivo: cada tenant puede acceder a su propia sesión.
            $crawler = $client->request('GET', '/admin/seguridad/contrasena');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Cambiar contraseña');
            $csrfBeforeExpiration = (string) $crawler
                ->filter('input[name="_csrf_token"]')
                ->attr('value');
            self::assertNotSame('', $csrfBeforeExpiration);

            foreach ([null, 'token-manipulado-'.bin2hex(random_bytes(6))] as $badToken) {
                $change = [
                    'current_password' => 'wrong-current-password',
                    'new_password' => 'New-secure-password-123!',
                    'password_confirmation' => 'New-secure-password-123!',
                ];
                if ($badToken !== null) {
                    $change['_csrf_token'] = $badToken;
                }
                $client->request('POST', '/admin/seguridad/contrasena', $change);
                self::assertResponseStatusCodeSame(403);

                $hash = $connection->fetchOne(
                    'SELECT password_hash FROM condor_user WHERE id = :id',
                    ['id' => $user->id()],
                );
                self::assertSame($passwords[$user->id()], $hash);

                // Un CSRF falso de logout tampoco puede cerrar la sesión activa.
                $logout = $badToken === null ? [] : ['_csrf_token' => $badToken];
                $client->request('POST', '/admin/logout', $logout);
                self::assertResponseStatusCodeSame(403);
                $client->request('GET', '/admin/seguridad/contrasena');
                self::assertResponseIsSuccessful();
            }

            // Simulación reproducible de caducidad: invalidación server-side
            // de la sesión autenticada antes de enviar una escritura.
            $client->getRequest()->getSession()->invalidate();
            $client->request('POST', '/admin/seguridad/contrasena', [
                'current_password' => 'wrong-current-password',
                'new_password' => 'New-secure-password-123!',
                'password_confirmation' => 'New-secure-password-123!',
                '_csrf_token' => $csrfBeforeExpiration,
            ]);
            self::assertResponseRedirects('/admin/login');
            $client->request('GET', '/admin/seguridad/contrasena');
            self::assertResponseRedirects('/admin/login');

            $hash = $connection->fetchOne(
                'SELECT password_hash FROM condor_user WHERE id = :id',
                ['id' => $user->id()],
            );
            self::assertSame($passwords[$user->id()], $hash);
        }

        self::assertSame($initialCount, $em->getRepository(Membership::class)->count([]));
    }
}
