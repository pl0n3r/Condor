<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\HumanResources\HrVacationCatalog;
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

/** M01 S08: real Symfony HTTP, fictional identities and no HR personal data. */
final class HrVacationControllerTest extends WebTestCase
{
    public function testOwnerSeesOnlyAuthorizedSyntheticVacationCalendar(): void
    {
        $browser = static::createClient();
        $database = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $database);
        [$tenant, $company, $otherCompany, $branch, $sibling, $foreignBranch, $owner] =
            $this->fixtures($database);
        $browser->loginUser($owner);

        $path = '/api/v1/branches/'.$branch->id().'/hr/vacations';
        $browser->request('GET', $path.'?company_id='.$company->id());
        self::assertResponseStatusCodeSame(200);
        $result = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($result['synthetic']);
        self::assertFalse($result['persisted']);
        self::assertFalse($result['juridically_binding']);
        self::assertCount(3, $result['vacations']);
        $first = $result['vacations'][0];
        self::assertTrue($first['synthetic']);
        self::assertSame('ficticio', $first['state']);
        self::assertSame('2027-01-12', $first['starts_on']);
        self::assertSame('2027-01-16', $first['ends_on']);
        self::assertMatchesRegularExpression('/\Afixture-vacation-[a-f0-9]{16}-enero-a\z/', $first['ref']);
        self::assertArrayNotHasKey('employee_id', $first);
        self::assertArrayNotHasKey('balance', $first);
        self::assertStringContainsString('private', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame('nosniff', $browser->getResponse()->headers->get('X-Content-Type-Options'));

        $browser->request('GET', $path.'/'.$first['ref']);
        self::assertResponseStatusCodeSame(200);
        $detail = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($first, $detail['vacation']);
        self::assertFalse($detail['persisted']);
        self::assertFalse($detail['juridically_binding']);

        $browser->request('GET', '/api/v1/branches/'.$sibling->id().'/hr/vacations');
        self::assertResponseStatusCodeSame(200);
        $otherScope = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotSame($first['ref'], $otherScope['vacations'][0]['ref']);
        $browser->request('GET', '/api/v1/branches/'.$sibling->id().'/hr/vacations/'.$first['ref']);
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', $path.'?company_id='.$otherCompany->id());
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', '/api/v1/branches/'.$foreignBranch->id().'/hr/vacations');
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString($foreignBranch->id(), (string) $browser->getResponse()->getContent());
        $browser->request('GET', $path.'?tenant_id='.$tenant->id());
        self::assertResponseStatusCodeSame(400);
        $browser->request('GET', $path.'?company_id%5B%5D='.$company->id());
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', $path.'/invalid-ref');
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', $path.'/fixture-vacation-0000000000000000-enero-a');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPermissionAndRealDataFlagAreFailClosed(): void
    {
        $browser = static::createClient();
        $database = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $database);
        [, , , $branch, , , $owner, $delegate] = $this->fixtures($database);
        $path = '/api/v1/branches/'.$branch->id().'/hr/vacations';

        $browser->loginUser($delegate);
        $browser->request('GET', $path);
        self::assertResponseStatusCodeSame(403);

        $browser->loginUser($owner);
        $old = getenv('HR_REAL_DATA_ENABLED');
        putenv('HR_REAL_DATA_ENABLED=true');
        try {
            $browser->request('GET', $path);
            self::assertResponseStatusCodeSame(404);
            $browser->request('GET', $path.'/fixture-vacation-0000000000000000-enero-a');
            self::assertResponseStatusCodeSame(404);
        } finally {
            if ($old === false) {
                putenv('HR_REAL_DATA_ENABLED');
            } else {
                putenv('HR_REAL_DATA_ENABLED='.$old);
            }
        }
    }

    public function testCatalogRejectsCrossScopeAndReferencesWithoutRecords(): void
    {
        $tenant = new Tenant('Tenant ficticio', 'vacation-main');
        $foreign = new Tenant('Tenant ajeno ficticio', 'vacation-other');
        $company = new LegalEntity($tenant, 'Empresa ficticia', null);
        $otherCompany = new LegalEntity($foreign, 'Empresa ajena ficticia', null);
        $branch = new Branch($tenant, 'Sede ficticia', 'local', $company);
        $otherBranch = new Branch($foreign, 'Sede ajena', 'foreign', $otherCompany);
        $catalog = new HrVacationCatalog();

        $list = $catalog->listFor($tenant, $company, $branch);
        self::assertCount(3, $list);
        self::assertSame($list[0], $catalog->findFor($tenant, $company, $branch, $list[0]['ref']));
        self::assertNull($catalog->findFor($tenant, $company, $branch, 'fixture-vacation-0000000000000000-enero-a'));
        self::assertNull($catalog->findFor($tenant, $company, $branch, 'untrusted-ref'));
        self::assertNotSame($list[0]['ref'], $catalog->listFor($foreign, $otherCompany, $otherBranch)[0]['ref']);

        $this->expectException(DomainException::class);
        $catalog->listFor($tenant, $otherCompany, $branch);
    }

    /** @return array{Tenant,LegalEntity,LegalEntity,Branch,Branch,Branch,User,User} */
    private function fixtures(EntityManagerInterface $database): array
    {
        $id = bin2hex(random_bytes(5));
        $tenant = new Tenant('Vacation principal', 'vac-main-'.$id);
        $foreign = new Tenant('Vacation externo', 'vac-external-'.$id);
        $company = new LegalEntity($tenant, 'Razón social sintética', null, true);
        $otherCompany = new LegalEntity($tenant, 'Otra empresa ficticia', null);
        $foreignCompany = new LegalEntity($foreign, 'Empresa ficticia ajena', null);
        $branch = new Branch($tenant, 'Sede inicial', 'inicial', $company, true);
        $sibling = new Branch($tenant, 'Sede secundaria', 'secundaria', $otherCompany);
        $foreignBranch = new Branch($foreign, 'Sede externa', 'externa', $foreignCompany);
        $owner = new User('vac-owner-'.$id.'@example.test', 'Owner ficticio');
        $delegate = new User('vac-delegate-'.$id.'@example.test', 'Delegado ficticio');
        $ownerMember = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        $delegateMember = new Membership($tenant, $delegate, 'ADMIN');
        $limited = new Role($tenant, 'Permiso limitado', ['catalog.view']);

        foreach ([$tenant, $foreign, $company, $otherCompany, $foreignCompany, $branch, $sibling, $foreignBranch, $owner, $delegate, $ownerMember, $delegateMember, $limited] as $record) {
            $database->persist($record);
        }
        $database->persist(new BranchRoleAssignment($delegateMember, $branch, $limited));
        $database->flush();
        return [$tenant, $company, $otherCompany, $branch, $sibling, $foreignBranch, $owner, $delegate];
    }
}
