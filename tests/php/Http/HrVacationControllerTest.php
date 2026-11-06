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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** M01 S08: the HTTP kernel uses only fictional records in its test database. */
final class HrVacationControllerTest extends WebTestCase
{
    public function testCalendarAndDetailAreScopeBoundAndStrictlyFictional(): void
    {
        $browser = static::createClient();
        $fixture = $this->seed();
        $browser->loginUser($fixture['owner']);

        $path = $this->endpoint($fixture['branch']);
        $body = $this->read($browser, $path.'?company_id='.$fixture['company']->id(), 200);
        self::assertSame(['synthetic' => true, 'persisted' => false, 'juridically_binding' => false], [
            'synthetic' => $body['synthetic'],
            'persisted' => $body['persisted'],
            'juridically_binding' => $body['juridically_binding'],
        ]);
        self::assertCount(3, $body['vacations']);
        $entry = $body['vacations'][0];
        self::assertMatchesRegularExpression('/\Afixture-vacation-[a-f0-9]{24}\z/D', $entry['ref']);
        self::assertSame('2027-01-12', $entry['starts_on']);
        self::assertSame('2027-01-16', $entry['ends_on']);
        self::assertTrue($entry['synthetic']);
        foreach (['employee', 'employee_id', 'balance', 'salary', 'person_id'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $entry);
        }
        self::assertStringContainsString('private', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame('nosniff', $browser->getResponse()->headers->get('X-Content-Type-Options'));

        $detail = $this->read($browser, $path.'/'.$entry['ref'], 200);
        self::assertSame($entry, $detail['vacation']);

        foreach (['sameCompanyBranch', 'otherCompanyBranch'] as $sibling) {
            $otherPath = $this->endpoint($fixture[$sibling]);
            $different = $this->read($browser, $otherPath, 200);
            self::assertNotSame($entry['ref'], $different['vacations'][0]['ref']);
            $this->read($browser, $otherPath.'/'.$entry['ref'], 404);
        }
    }

    public function testInvalidFiltersForeignTenantAndOpaqueReferenceFailClosed(): void
    {
        $browser = static::createClient();
        $fixture = $this->seed();
        $browser->loginUser($fixture['owner']);
        $path = $this->endpoint($fixture['branch']);

        $attempts = [
            [$path.'?company_id='.$fixture['otherCompany']->id(), 404],
            [$path.'?tenant_id='.$fixture['tenant']->id(), 400],
            [$path.'?company_id%5B%5D='.$fixture['company']->id(), 404],
            [$path.'/random-opaque-reference', 404],
            [$path.'/fixture-vacation-'.str_repeat('0', 24), 404],
            [$this->endpoint($fixture['foreignBranch']), 404],
        ];
        foreach ($attempts as [$url, $expected]) {
            $this->read($browser, $url, $expected);
            self::assertStringNotContainsString(
                $fixture['foreignBranch']->id(),
                (string) $browser->getResponse()->getContent(),
            );
        }
    }

    public function testDeniedPermissionAndRealDataFlagCannotShowSyntheticPreview(): void
    {
        $browser = static::createClient();
        $fixture = $this->seed();
        $browser->loginUser($fixture['delegate']);
        $path = $this->endpoint($fixture['branch']);
        $this->read($browser, $path, 403);

        $browser->loginUser($fixture['owner']);
        $oldValue = getenv('HR_REAL_DATA_ENABLED');
        putenv('HR_REAL_DATA_ENABLED=1');
        try {
            $this->read($browser, $path, 404);
            $this->read($browser, $path.'/fixture-vacation-'.str_repeat('0', 24), 404);
        } finally {
            putenv($oldValue === false ? 'HR_REAL_DATA_ENABLED' : 'HR_REAL_DATA_ENABLED='.$oldValue);
        }
    }

    public function testServiceRefusesAnUnrelatedLegalEntity(): void
    {
        $tenant = new Tenant('M01 ficticio', 'm01-vac-main');
        $foreign = new Tenant('M01 externo', 'm01-vac-other');
        $company = new LegalEntity($tenant, 'Entidad de ensayo', null);
        $foreignCompany = new LegalEntity($foreign, 'Entidad externa ficticia', null);
        $branch = new Branch($tenant, 'Sede de ensayo', 'principal', $company);
        $catalog = new HrVacationCatalog();
        $entries = $catalog->listFor($tenant, $company, $branch);
        self::assertCount(3, $entries);
        self::assertSame($entries[0], $catalog->findFor($tenant, $company, $branch, $entries[0]['ref']));
        self::assertNull($catalog->findFor($tenant, $company, $branch, 'invalid'));
        self::assertNull($catalog->findFor($tenant, $company, $branch, 'fixture-vacation-'.str_repeat('0', 24)));

        $this->expectException(DomainException::class);
        $catalog->listFor($tenant, $foreignCompany, $branch);
    }

    private function endpoint(Branch $branch): string
    {
        return '/api/v1/branches/'.$branch->id().'/hr/vacations';
    }

    /** @return array<string, mixed> */
    private function read(KernelBrowser $browser, string $uri, int $status): array
    {
        $browser->request('GET', $uri);
        self::assertResponseStatusCodeSame($status);
        if ($status !== 200) {
            return [];
        }
        return json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array{tenant: Tenant, company: LegalEntity, otherCompany: LegalEntity, branch: Branch, sameCompanyBranch: Branch, otherCompanyBranch: Branch, foreignBranch: Branch, owner: User, delegate: User} */
    private function seed(): array
    {
        $database = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $database);
        $suffix = bin2hex(random_bytes(5));
        $tenant = new Tenant('Vista principal ficticia', 'cal-main-'.$suffix);
        $foreignTenant = new Tenant('Vista externa ficticia', 'cal-foreign-'.$suffix);
        $company = new LegalEntity($tenant, 'Compañía de ensayo', null, true);
        $otherCompany = new LegalEntity($tenant, 'Compañía secundaria', null);
        $foreignCompany = new LegalEntity($foreignTenant, 'Compañía ajena', null);
        $branch = new Branch($tenant, 'Sede principal de prueba', 'principal', $company, true);
        $sameCompanyBranch = new Branch($tenant, 'Sede satélite', 'satelite', $company);
        $otherCompanyBranch = new Branch($tenant, 'Sede secundaria', 'secundaria', $otherCompany);
        $foreignBranch = new Branch($foreignTenant, 'Sede ajena', 'externa', $foreignCompany);
        $owner = new User('vac-owner-'.$suffix.'@example.test', 'Propietario de prueba');
        $delegate = new User('vac-reader-'.$suffix.'@example.test', 'Rol limitado');
        $member = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        $limitedMember = new Membership($tenant, $delegate, 'ADMIN');
        $role = new Role($tenant, 'Sin permiso para RRHH', ['catalog.view']);

        $entities = [
            $tenant, $foreignTenant, $company, $otherCompany, $foreignCompany,
            $branch, $sameCompanyBranch, $otherCompanyBranch, $foreignBranch,
            $owner, $delegate, $member, $limitedMember, $role,
        ];
        foreach ($entities as $entity) {
            $database->persist($entity);
        }
        $database->persist(new BranchRoleAssignment($limitedMember, $branch, $role));
        $database->flush();

        return compact(
            'tenant', 'company', 'otherCompany', 'branch', 'sameCompanyBranch',
            'otherCompanyBranch', 'foreignBranch', 'owner', 'delegate',
        );
    }
}
