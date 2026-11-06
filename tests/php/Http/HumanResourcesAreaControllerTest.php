<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\HumanResources\AreaCatalog;
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

/** M01 S03: authorization + three-scope isolation with synthetic identities. */
final class HumanResourcesAreaControllerTest extends WebTestCase
{
    public function testOwnerSeesOnlyTheAuthorizedSyntheticBranchCatalog(): void
    {
        $browser = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        [$tenant, $company, $otherCompany, $branch, $sameTenantBranch, $foreignBranch, $owner] =
            $this->fixtures($entityManager);

        $browser->loginUser($owner);
        $path = '/api/v1/branches/'.$branch->id().'/hr/areas';
        $browser->request('GET', $path.'?company_id='.$company->id());
        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['synthetic']);
        self::assertFalse($payload['persisted']);
        self::assertFalse($payload['juridically_binding']);
        self::assertCount(3, $payload['areas']);
        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        $ref = $payload['areas'][0]['ref'];
        self::assertTrue($payload['areas'][0]['synthetic']);

        $browser->request('GET', $path.'/'.$ref);
        self::assertResponseStatusCodeSame(200);
        $detail = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($ref, $detail['area']['ref']);

        $browser->request('GET', '/api/v1/branches/'.$sameTenantBranch->id().'/hr/areas');
        self::assertResponseStatusCodeSame(200);
        $different = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotSame($ref, $different['areas'][0]['ref']);
        $browser->request('GET', '/api/v1/branches/'.$sameTenantBranch->id().'/hr/areas/'.$ref);
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', $path.'?company_id='.$otherCompany->id());
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', '/api/v1/branches/'.$foreignBranch->id().'/hr/areas');
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString($foreignBranch->id(), (string) $browser->getResponse()->getContent());
        $browser->request('GET', $path.'?tenant_id='.$tenant->id());
        self::assertResponseStatusCodeSame(400);
        $browser->request('GET', $path.'?company_id%5B%5D='.$company->id());
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', $path.'/invalid-ref');
        self::assertResponseStatusCodeSame(404);
    }

    public function testMissingPermissionAndRealDataFlagFailClosed(): void
    {
        $browser = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        [, , , $branch, , , $owner, $delegate] = $this->fixtures($entityManager);
        $path = '/api/v1/branches/'.$branch->id().'/hr/areas';

        $browser->loginUser($delegate);
        $browser->request('GET', $path);
        self::assertResponseStatusCodeSame(403);

        $browser->loginUser($owner);
        $before = getenv('HR_REAL_DATA_ENABLED');
        putenv('HR_REAL_DATA_ENABLED=true');
        try {
            $browser->request('GET', $path);
            self::assertResponseStatusCodeSame(404);
        } finally {
            if ($before === false) {
                putenv('HR_REAL_DATA_ENABLED');
            } else {
                putenv('HR_REAL_DATA_ENABLED='.$before);
            }
        }
    }

    public function testServiceRejectsCrossTenantCompanyAndBranchWithoutReadingRecords(): void
    {
        $tenant = new Tenant('Fixture principal', 'hr-a');
        $other = new Tenant('Fixture externa', 'hr-b');
        $company = new LegalEntity($tenant, 'Empresa ficticia', null);
        $otherCompany = new LegalEntity($other, 'Ajena ficticia', null);
        $branch = new Branch($tenant, 'Local', 'local', $company);
        $catalog = new AreaCatalog();
        self::assertCount(3, $catalog->listFor($tenant, $company, $branch));
        $this->expectException(DomainException::class);
        $catalog->listFor($tenant, $otherCompany, $branch);
    }

    /** @return array{Tenant,LegalEntity,LegalEntity,Branch,Branch,Branch,User,User} */
    private function fixtures(EntityManagerInterface $database): array
    {
        $id = bin2hex(random_bytes(5));
        $tenant = new Tenant('HR principal', 'hr-main-'.$id);
        $foreign = new Tenant('HR externo', 'hr-external-'.$id);
        $company = new LegalEntity($tenant, 'Razón social sintética', null, true);
        $otherCompany = new LegalEntity($tenant, 'Razón social ficticia secundaria', null);
        $foreignCompany = new LegalEntity($foreign, 'Razón social externa ficticia', null);
        $branch = new Branch($tenant, 'Sede inicial', 'inicial', $company, true);
        $sameTenant = new Branch($tenant, 'Sede secundaria', 'secundaria', $otherCompany);
        $foreignBranch = new Branch($foreign, 'Sede externa', 'externa', $foreignCompany);
        $owner = new User('hr-owner-'.$id.'@example.test', 'Owner sintético');
        $delegate = new User('hr-delegate-'.$id.'@example.test', 'Delegado sintético');
        $ownerMember = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        $delegateMember = new Membership($tenant, $delegate, 'ADMIN');
        $limited = new Role($tenant, 'Catálogo limitado', ['catalog.view']);
        foreach ([$tenant, $foreign, $company, $otherCompany, $foreignCompany, $branch, $sameTenant, $foreignBranch, $owner, $delegate, $ownerMember, $delegateMember, $limited] as $row) {
            $database->persist($row);
        }
        $database->persist(new BranchRoleAssignment($delegateMember, $branch, $limited));
        $database->flush();
        return [$tenant, $company, $otherCompany, $branch, $sameTenant, $foreignBranch, $owner, $delegate];
    }
}
