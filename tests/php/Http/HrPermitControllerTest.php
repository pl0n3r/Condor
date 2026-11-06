<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\HumanResources\HrPermitCatalog;
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

/** M01 S07: real Symfony HTTP tests on synthetic tenant/company/branch identities. */
final class HrPermitControllerTest extends WebTestCase
{
    public function testOwnerCanListAndReadScopedSyntheticPermitsOnly(): void
    {
        $browser = static::createClient();
        $database = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $database);
        [$tenant, $company, $otherCompany, $branch, $sameTenantBranch, $foreignBranch, $owner] = $this->fixtures($database);

        $browser->loginUser($owner);
        $path = '/api/v1/branches/'.$branch->id().'/hr/permits';
        $browser->request('GET', $path.'?company_id='.$company->id());
        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['synthetic']);
        self::assertFalse($payload['persisted']);
        self::assertFalse($payload['juridically_binding']);
        self::assertCount(3, $payload['permits']);
        $ref = $payload['permits'][0]['ref'];
        self::assertTrue($payload['permits'][0]['synthetic']);
        self::assertSame('PENDIENTE', $payload['permits'][0]['state']);
        self::assertFalse($payload['permits'][0]['automatically_approved']);
        self::assertArrayNotHasKey('employee_ref', $payload['permits'][0]);
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));

        $browser->request('GET', $path.'/'.$ref.'?company_id='.$company->id());
        self::assertResponseStatusCodeSame(200);
        $detail = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($ref, $detail['permit']['ref']);
        self::assertFalse($detail['persisted']);

        $browser->request('GET', '/api/v1/branches/'.$sameTenantBranch->id().'/hr/permits');
        self::assertResponseStatusCodeSame(200);
        $other = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotSame($ref, $other['permits'][0]['ref']);
        $browser->request('GET', '/api/v1/branches/'.$sameTenantBranch->id().'/hr/permits/'.$ref);
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', $path.'?company_id='.$otherCompany->id());
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', $path.'/'.$ref.'?company_id='.$otherCompany->id());
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', '/api/v1/branches/'.$foreignBranch->id().'/hr/permits');
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString($foreignBranch->id(), (string) $browser->getResponse()->getContent());
        $browser->request('GET', $path.'?tenant_id='.$tenant->id());
        self::assertResponseStatusCodeSame(400);
        $browser->request('GET', $path.'?company_id%5B%5D='.$company->id());
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', $path.'/invalid-ref');
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', $path.'/'.$ref.'?unexpected=1');
        self::assertResponseStatusCodeSame(400);
    }

    public function testMissingPermissionAndRealDataFlagFailClosed(): void
    {
        $browser = static::createClient();
        $database = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $database);
        [, , , $branch, , , $owner, $delegate] = $this->fixtures($database);
        $path = '/api/v1/branches/'.$branch->id().'/hr/permits';

        $browser->loginUser($delegate);
        $browser->request('GET', $path);
        self::assertResponseStatusCodeSame(403);

        $browser->loginUser($owner);
        $previous = getenv('HR_REAL_DATA_ENABLED');
        putenv('HR_REAL_DATA_ENABLED=true');
        try {
            $browser->request('GET', $path);
            self::assertResponseStatusCodeSame(404);
            $browser->request('GET', $path.'/fixture-permit-0000000000000000-personal');
            self::assertResponseStatusCodeSame(404);
        } finally {
            if ($previous === false) {
                putenv('HR_REAL_DATA_ENABLED');
            } else {
                putenv('HR_REAL_DATA_ENABLED='.$previous);
            }
        }
    }

    public function testCatalogRejectsCrossScopeAndNeverApproves(): void
    {
        $tenant = new Tenant('HR ficticio principal', 'hr-p');
        $foreign = new Tenant('HR ficticio externo', 'hr-q');
        $company = new LegalEntity($tenant, 'Empresa de prueba', null);
        $otherCompany = new LegalEntity($foreign, 'Empresa ajena ficticia', null);
        $branch = new Branch($tenant, 'Sede prueba', 'prueba', $company);
        $catalog = new HrPermitCatalog();
        $permits = $catalog->listFor($tenant, $company, $branch);
        self::assertCount(3, $permits);
        self::assertSame($permits, $catalog->listFor($tenant, $company, $branch));
        self::assertSame($permits[0], $catalog->findFor($tenant, $company, $branch, $permits[0]['ref']));
        self::assertNull($catalog->findFor($tenant, $company, $branch, 'invalid'));
        self::assertFalse($permits[0]['automatically_approved']);
        $this->expectException(DomainException::class);
        $catalog->listFor($tenant, $otherCompany, $branch);
    }

    /** @return array{Tenant,LegalEntity,LegalEntity,Branch,Branch,Branch,User,User} */
    private function fixtures(EntityManagerInterface $database): array
    {
        $id = bin2hex(random_bytes(5));
        $tenant = new Tenant('HR permisos', 'hr-permit-'.$id);
        $foreign = new Tenant('HR externo', 'hr-other-'.$id);
        $company = new LegalEntity($tenant, 'Empresa sintética', null, true);
        $otherCompany = new LegalEntity($tenant, 'Empresa secundaria sintética', null);
        $foreignCompany = new LegalEntity($foreign, 'Empresa externa sintética', null);
        $branch = new Branch($tenant, 'Sede principal', 'principal', $company, true);
        $sameTenantBranch = new Branch($tenant, 'Sede secundaria', 'secundaria', $otherCompany);
        $foreignBranch = new Branch($foreign, 'Sede ajena', 'ajena', $foreignCompany);
        $owner = new User('hr-permit-owner-'.$id.'@example.test', 'Dueño ficticio');
        $delegate = new User('hr-permit-delegate-'.$id.'@example.test', 'Delegado ficticio');
        $ownerMembership = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        $delegateMembership = new Membership($tenant, $delegate, 'ADMIN');
        $restricted = new Role($tenant, 'Rol limitado', ['catalog.view']);
        foreach ([$tenant, $foreign, $company, $otherCompany, $foreignCompany, $branch, $sameTenantBranch, $foreignBranch, $owner, $delegate, $ownerMembership, $delegateMembership, $restricted] as $entity) {
            $database->persist($entity);
        }
        $database->persist(new BranchRoleAssignment($delegateMembership, $branch, $restricted));
        $database->flush();
        return [$tenant, $company, $otherCompany, $branch, $sameTenantBranch, $foreignBranch, $owner, $delegate];
    }
}
