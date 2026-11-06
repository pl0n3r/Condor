<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\HumanResources\HrChangeCatalog;
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

/** M01 S06 kernel regression: synthetic-only, read-only and three-scope isolation. */
final class HrChangeControllerTest extends WebTestCase
{
    public function testOwnerCanReadOnlyScopedSyntheticChanges(): void
    {
        $browser = static::createClient();
        $database = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $database);
        [, $company, $otherCompany, $branch, $sameTenantBranch, $foreignBranch, $owner] =
            $this->fixtures($database);

        $browser->loginUser($owner);
        $path = '/api/v1/branches/'.$branch->id().'/hr/changes';
        $browser->request('GET', $path.'?company_id='.$company->id());
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['synthetic']);
        self::assertFalse($body['persisted']);
        self::assertFalse($body['juridically_binding']);
        self::assertCount(3, $body['changes']);
        self::assertSame('SOLO_LECTURA', $body['changes'][0]['state']);
        self::assertTrue($body['changes'][0]['synthetic']);
        self::assertStringContainsString('private', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        $ref = $body['changes'][0]['ref'];

        $browser->request('GET', $path.'/'.$ref);
        self::assertResponseStatusCodeSame(200);
        $detail = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($ref, $detail['change']['ref']);

        $otherPath = '/api/v1/branches/'.$sameTenantBranch->id().'/hr/changes';
        $browser->request('GET', $otherPath);
        self::assertResponseStatusCodeSame(200);
        $other = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotSame($ref, $other['changes'][0]['ref']);
        $browser->request('GET', $otherPath.'/'.$ref);
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', $path.'?company_id='.$otherCompany->id());
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', '/api/v1/branches/'.$foreignBranch->id().'/hr/changes');
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString($foreignBranch->id(), (string) $browser->getResponse()->getContent());
        $browser->request('GET', $path.'?tenant_id=fixture');
        self::assertResponseStatusCodeSame(400);
        $browser->request('GET', $path.'?company_id%5B%5D='.$company->id());
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', $path.'/invalid-ref');
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', $path.'/fixture-hr-change-0000000000000000-ingreso');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousAndRoleWithoutReadPermissionAreDenied(): void
    {
        $browser = static::createClient();
        $database = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $database);
        [, , , $branch, , , , $delegate] = $this->fixtures($database);
        $path = '/api/v1/branches/'.$branch->id().'/hr/changes';

        $browser->request('GET', $path);
        self::assertResponseStatusCodeSame(403);
        $browser->loginUser($delegate);
        $browser->request('GET', $path);
        self::assertResponseStatusCodeSame(403);
    }

    public function testRealDataFlagDisablesSyntheticEndpoints(): void
    {
        $browser = static::createClient();
        $database = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $database);
        [, , , $branch, , , $owner] = $this->fixtures($database);
        $browser->loginUser($owner);
        $path = '/api/v1/branches/'.$branch->id().'/hr/changes';

        $before = getenv('HR_REAL_DATA_ENABLED');
        putenv('HR_REAL_DATA_ENABLED=true');
        try {
            $browser->request('GET', $path);
            self::assertResponseStatusCodeSame(404);
            $browser->request('GET', $path.'/fixture-hr-change-0000000000000000-ingreso');
            self::assertResponseStatusCodeSame(404);
        } finally {
            if ($before === false) {
                putenv('HR_REAL_DATA_ENABLED');
            } else {
                putenv('HR_REAL_DATA_ENABLED='.$before);
            }
        }
    }

    public function testCatalogRejectsForeignCompanyWithoutReadingEmployees(): void
    {
        $tenant = new Tenant('Principal ficticio', 'changes-a');
        $other = new Tenant('Ajeno ficticio', 'changes-b');
        $company = new LegalEntity($tenant, 'Empresa ficticia', null);
        $foreign = new LegalEntity($other, 'Otra ficticia', null);
        $branch = new Branch($tenant, 'Sede ficticia', 'sede', $company);
        $catalog = new HrChangeCatalog();
        self::assertCount(3, $catalog->listFor($tenant, $company, $branch));
        $this->expectException(DomainException::class);
        $catalog->listFor($tenant, $foreign, $branch);
    }

    /** @return array{Tenant,LegalEntity,LegalEntity,Branch,Branch,Branch,User,User} */
    private function fixtures(EntityManagerInterface $database): array
    {
        $id = bin2hex(random_bytes(5));
        $tenant = new Tenant('HR principal', 'changes-main-'.$id);
        $foreignTenant = new Tenant('HR externo', 'changes-foreign-'.$id);
        $company = new LegalEntity($tenant, 'Empresa ficticia', null, true);
        $otherCompany = new LegalEntity($tenant, 'Otra empresa ficticia', null);
        $foreignCompany = new LegalEntity($foreignTenant, 'Empresa externa ficticia', null);
        $branch = new Branch($tenant, 'Sede principal', 'principal', $company, true);
        $sameTenantBranch = new Branch($tenant, 'Otra sede', 'otra', $otherCompany);
        $foreignBranch = new Branch($foreignTenant, 'Sede externa', 'externa', $foreignCompany);
        $owner = new User('changes-owner-'.$id.'@example.test', 'Dueño ficticio');
        $delegate = new User('changes-delegate-'.$id.'@example.test', 'Delegado ficticio');
        $ownerMember = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        $delegateMember = new Membership($tenant, $delegate, 'ADMIN');
        $limited = new Role($tenant, 'Rol restringido', ['catalog.view']);
        foreach ([$tenant, $foreignTenant, $company, $otherCompany, $foreignCompany, $branch,
            $sameTenantBranch, $foreignBranch, $owner, $delegate, $ownerMember, $delegateMember, $limited] as $row) {
            $database->persist($row);
        }
        $database->persist(new BranchRoleAssignment($delegateMember, $branch, $limited));
        $database->flush();

        return [$tenant, $company, $otherCompany, $branch, $sameTenantBranch, $foreignBranch, $owner, $delegate];
    }
}
