<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\HumanResources\HrPortalCatalog;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** M01 S11: rutas y permisos reales, únicamente con fixtures sintéticos. */
final class HrPortalControllerTest extends WebTestCase
{
    public function testOwnerCanOnlyReadSyntheticItemsWithinAllThreeScopes(): void
    {
        $browser = static::createClient();
        $db = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $db);
        [, $company, $otherCompany, $branch, $otherBranch, $foreignBranch, $owner] = $this->fixtures($db);
        $browser->loginUser($owner);
        $path = '/api/v1/branches/'.$branch->id().'/hr/employee-portal';

        $browser->request('GET', $path.'?company_id='.$company->id());
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['synthetic']);
        self::assertFalse($body['persisted']);
        self::assertFalse($body['juridically_binding']);
        self::assertCount(3, $body['items']);
        self::assertSame('SOLO_LECTURA', $body['items'][0]['state']);
        self::assertTrue($body['items'][0]['synthetic']);
        self::assertStringContainsString('private', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        $ref = $body['items'][0]['ref'];

        $browser->request('GET', $path.'/'.$ref);
        self::assertResponseStatusCodeSame(200);
        $detail = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($ref, $detail['item']['ref']);

        $otherPath = '/api/v1/branches/'.$otherBranch->id().'/hr/employee-portal';
        $browser->request('GET', $otherPath);
        self::assertResponseStatusCodeSame(200);
        $other = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotSame($ref, $other['items'][0]['ref']);
        $browser->request('GET', $otherPath.'/'.$ref);
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', $path.'?company_id='.$otherCompany->id());
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', '/api/v1/branches/'.$foreignBranch->id().'/hr/employee-portal');
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString($foreignBranch->id(), (string) $browser->getResponse()->getContent());
        $browser->request('GET', $path.'?tenant_id=fixture');
        self::assertResponseStatusCodeSame(400);
        $browser->request('GET', $path.'?company_id%5B%5D='.$company->id());
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', $path.'/invalid-ref');
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', $path.'/fixture-hr-portal-0000000000000000-avisos');
        self::assertResponseStatusCodeSame(404);
        $browser->request('POST', $path);
        self::assertResponseStatusCodeSame(405);
    }

    public function testAnonymousAndRestrictedRoleCannotReadPortal(): void
    {
        $browser = static::createClient();
        $db = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $db);
        [, , , $branch, , , , $delegate] = $this->fixtures($db);
        $path = '/api/v1/branches/'.$branch->id().'/hr/employee-portal';

        $browser->request('GET', $path);
        self::assertResponseStatusCodeSame(401);
        $browser->loginUser($delegate);
        $browser->request('GET', $path);
        self::assertResponseStatusCodeSame(403);
    }

    public function testRealDataSwitchFailsClosedForListAndDetail(): void
    {
        $browser = static::createClient();
        $db = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $db);
        [, , , $branch, , , $owner] = $this->fixtures($db);
        $browser->loginUser($owner);
        $path = '/api/v1/branches/'.$branch->id().'/hr/employee-portal';
        $previous = getenv('HR_REAL_DATA_ENABLED');
        putenv('HR_REAL_DATA_ENABLED=true');
        try {
            $browser->request('GET', $path);
            self::assertResponseStatusCodeSame(404);
            $browser->request('GET', $path.'/fixture-hr-portal-0000000000000000-avisos');
            self::assertResponseStatusCodeSame(404);
        } finally {
            if ($previous === false) {
                putenv('HR_REAL_DATA_ENABLED');
            } else {
                putenv('HR_REAL_DATA_ENABLED='.$previous);
            }
        }
    }

    public function testCatalogRejectsForeignCompany(): void
    {
        $tenant = new Tenant('Principal ficticio', 'portal-a');
        $foreign = new Tenant('Ajeno ficticio', 'portal-b');
        $company = new LegalEntity($tenant, 'Empresa ficticia', null);
        $foreignCompany = new LegalEntity($foreign, 'Ajena ficticia', null);
        $branch = new Branch($tenant, 'Sede ficticia', 'portal', $company);
        $catalog = new HrPortalCatalog();
        self::assertCount(3, $catalog->listFor($tenant, $company, $branch));
        $this->expectException(DomainException::class);
        $catalog->listFor($tenant, $foreignCompany, $branch);
    }

    /** @return array{Tenant,LegalEntity,LegalEntity,Branch,Branch,Branch,User,User} */
    private function fixtures(EntityManagerInterface $db): array
    {
        $id = bin2hex(random_bytes(5));
        $tenant = new Tenant('Portal principal', 'portal-main-'.$id);
        $otherTenant = new Tenant('Portal externo', 'portal-foreign-'.$id);
        $company = new LegalEntity($tenant, 'Empresa ficticia', null, true);
        $otherCompany = new LegalEntity($tenant, 'Otra empresa ficticia', null);
        $foreignCompany = new LegalEntity($otherTenant, 'Empresa externa ficticia', null);
        $branch = new Branch($tenant, 'Sede principal', 'principal', $company, true);
        $otherBranch = new Branch($tenant, 'Otra sede', 'otra', $otherCompany);
        $foreignBranch = new Branch($otherTenant, 'Sede externa', 'externa', $foreignCompany);
        $owner = new User('portal-owner-'.$id.'@example.test', 'Dueño ficticio');
        $delegate = new User('portal-delegate-'.$id.'@example.test', 'Delegado ficticio');
        $ownerMember = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        $delegateMember = new Membership($tenant, $delegate, 'ADMIN');
        $role = new Role($tenant, 'Rol restringido', ['catalog.view']);
        foreach ([$tenant, $otherTenant, $company, $otherCompany, $foreignCompany, $branch,
            $otherBranch, $foreignBranch, $owner, $delegate, $ownerMember, $delegateMember, $role] as $row) {
            $db->persist($row);
        }
        $db->persist(new BranchRoleAssignment($delegateMember, $branch, $role));
        $db->flush();

        return [$tenant, $company, $otherCompany, $branch, $otherBranch, $foreignBranch, $owner, $delegate];
    }
}
