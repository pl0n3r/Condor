<?php

declare(strict_types=1);

namespace App\Tests\Http\Knowledge;

use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * #778: pruebas HTTP reales de la superficie de conocimiento owner-only.
 * Sin datos de producción, escritura de conocimiento ni nuevas rutas.
 */
final class AdminKnowledgeHttpPermissionsTest extends WebTestCase
{
    public function testOnlyOwnerCanReadAndNoTenantCanWriteKnowledge(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $suffix = bin2hex(random_bytes(5));
        $tenantA = new Tenant('Conocimiento A '.$suffix, 'knowledge-a-'.$suffix);
        $tenantB = new Tenant('Conocimiento B '.$suffix, 'knowledge-b-'.$suffix);
        $tenantUserA = new User('knowledge-a-'.$suffix.'@example.test', 'Tenant A');
        $tenantUserB = new User('knowledge-b-'.$suffix.'@example.test', 'Tenant B');
        $staff = new User(
            'knowledge-staff-'.$suffix.'@example.test',
            'Staff de plataforma',
            [User::ROLE_PLATFORM_STAFF],
        );
        $owner = new User(
            'knowledge-owner-'.$suffix.'@example.test',
            'Propietario de plataforma',
            [User::ROLE_PLATFORM_OWNER],
        );
        $membershipA = new Membership($tenantA, $tenantUserA, Membership::ROLE_OWNER);
        $membershipB = new Membership($tenantB, $tenantUserB, Membership::ROLE_OWNER);

        foreach (
            [
                $tenantA, $tenantB, $tenantUserA, $tenantUserB,
                $staff, $owner, $membershipA, $membershipB,
            ] as $entity
        ) {
            $em->persist($entity);
        }
        $em->flush();

        $before = [
            'users' => $em->getRepository(User::class)->count([]),
            'tenants' => $em->getRepository(Tenant::class)->count([]),
            'memberships' => $em->getRepository(Membership::class)->count([]),
        ];
        $route = '/adminpl0n3r/conocimiento';

        // La ruta de plataforma no revela el panel a visitantes anónimos.
        $client->request('GET', $route);
        self::assertNotSame(200, $client->getResponse()->getStatusCode());

        // Tener roles en cualquiera de los dos tenants, o ser staff, no
        // concede automáticamente el rol global de platform owner.
        foreach ([$tenantUserA, $tenantUserB, $staff] as $unauthorizedUser) {
            $client->loginUser($unauthorizedUser);
            $client->request('GET', $route);
            self::assertResponseStatusCodeSame(403);
        }

        $client->loginUser($owner);
        $client->request('GET', $route);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Estado de la fuente canónica');

        // IDs de otros tenants y parámetros no contratados no modifican la
        // autoridad del panel ni filtran datos de esos tenants.
        foreach ([$tenantA, $tenantB] as $tenant) {
            $client->request('GET', $route.'?tenant_id='.$tenant->id().'&state=published');
            self::assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($tenantA->id(), $html);
            self::assertStringNotContainsString($tenantB->id(), $html);
            self::assertStringNotContainsString($tenantA->name(), $html);
            self::assertStringNotContainsString($tenantB->name(), $html);
        }

        // El controlador actual es estrictamente GET-only. Ni siquiera el
        // propietario puede alterar contenido mediante verbos no registrados,
        // enviando un tenant ajeno, estado o campo arbitrario.
        foreach (['POST', 'PATCH', 'PUT', 'DELETE'] as $method) {
            $client->request($method, $route, [
                'tenant_id' => $tenantB->id(),
                'state' => 'published',
                'unexpected_field' => 'invalid',
            ]);
            self::assertResponseStatusCodeSame(405);
        }

        // Los rechazos no deben generar efectos secundarios en la base.
        self::assertSame($before['users'], $em->getRepository(User::class)->count([]));
        self::assertSame($before['tenants'], $em->getRepository(Tenant::class)->count([]));
        self::assertSame(
            $before['memberships'],
            $em->getRepository(Membership::class)->count([]),
        );
    }
}
