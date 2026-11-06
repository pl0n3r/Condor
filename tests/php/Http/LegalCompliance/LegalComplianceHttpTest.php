<?php

declare(strict_types=1);

namespace App\Tests\Http\LegalCompliance;

use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LegalComplianceHttpTest extends WebTestCase
{
    public function testSixHttpViewsAndUnauthorizedMutations(): void
    {
        putenv('CONDOR_LEGAL_SYNTHETIC_API=1');
        putenv('CONDOR_PRODUCTION_STAGE=construccion');
        try {
            $browser = static::createClient();
            $em = static::getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            $suffix = bin2hex(random_bytes(4));
            $tenant = new Tenant('Empresa ficticia A', 'm06-a-'.$suffix);
            $foreign = new Tenant('Empresa ficticia B', 'm06-b-'.$suffix);
            $branch = new Branch($tenant, 'Sede ficticia', 'm06', null, true);
            $other = new Branch($foreign, 'Sede B', 'm06b', null, true);
            $owner = new User('m06-owner-'.$suffix.'@example.test', 'Propietario');
            $delegate = new User('m06-delegate-'.$suffix.'@example.test', 'Lector');
            $access = new Membership($tenant, $delegate, 'ADMIN');
            $role = new Role($tenant, 'Sin permisos', ['roles.view']);
            foreach ([$tenant, $foreign, $branch, $other, $owner, $delegate,
                new Membership($tenant, $owner, Membership::ROLE_OWNER), $access, $role] as $item) {
                $em->persist($item);
            }
            $em->persist(new BranchRoleAssignment($access, $branch, $role));
            $em->flush();
            $browser->loginUser($owner);
            $html = $browser->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            $csrf = (string) $html->filter('#condor-admin-root')->attr('data-access-token');
            $url = '/api/v1/branches/'.$branch->id().'/legal-compliance/';
            foreach (['perfil', 'catalogo', 'matriz', 'calendario', 'evaluaciones', 'auditorias'] as $section) {
                $browser->request('GET', $url.$section);
                self::assertResponseStatusCodeSame(200);
                $out = json_decode((string) $browser->getResponse()->getContent(), true);
                self::assertSame([], $out['rows']);
                self::assertFalse($out['automatically_approved']);
                self::assertFalse($out['automatically_closed']);
            }
            $browser->jsonRequest('POST', $url.'fixtures', ['scenario' => 'demo']);
            self::assertResponseStatusCodeSame(403);
            $browser->jsonRequest('POST', $url.'fixtures',
                ['scenario' => 'demo', 'customer_email' => 'persona@example.test'],
                ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(422);
            $browser->jsonRequest('POST', $url.'fixtures', ['scenario' => 'real'],
                ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(422);
            $browser->jsonRequest('POST', $url.'fixtures', ['scenario' => 'demo'],
                ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(200);
            foreach (['perfil', 'catalogo', 'matriz', 'calendario', 'evaluaciones', 'auditorias'] as $section) {
                $browser->request('GET', $url.$section);
                self::assertResponseStatusCodeSame(200);
                $out = json_decode((string) $browser->getResponse()->getContent(), true);
                self::assertNotEmpty($out['rows'], $section);
                self::assertTrue($out['requires_professional_review']);
            }
            $browser->request('GET', '/api/v1/branches/'.$other->id().'/legal-compliance/perfil');
            self::assertResponseStatusCodeSame(404);
            $browser->jsonRequest('PATCH', $url.'fixtures', ['scenario' => 'vacio']);
            self::assertResponseStatusCodeSame(403);
            $browser->jsonRequest('PATCH', $url.'fixtures', ['scenario' => 'vacio'],
                ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(200);
            $browser->request('GET', $url.'perfil');
            self::assertSame([], json_decode((string) $browser->getResponse()->getContent(), true)['rows']);
            $browser->loginUser($delegate);
            $browser->request('GET', $url.'perfil');
            self::assertResponseStatusCodeSame(403);
            $browser->jsonRequest('POST', $url.'fixtures', ['scenario' => 'demo'],
                ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(403);
            putenv('CONDOR_LEGAL_SYNTHETIC_API=0');
            $browser->loginUser($owner);
            $browser->request('GET', $url.'perfil');
            self::assertResponseStatusCodeSame(404);
        } finally {
            putenv('CONDOR_LEGAL_SYNTHETIC_API');
            putenv('CONDOR_PRODUCTION_STAGE');
        }
    }
}
