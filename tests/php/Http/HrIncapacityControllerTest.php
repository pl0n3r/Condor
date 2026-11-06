<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\HumanResources\HrIncapacityCatalog;
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

final class HrIncapacityControllerTest extends WebTestCase
{
    public function testListAndDetailContainOnlyFictionalScopeBoundAbsences(): void
    {
        $browser = static::createClient();
        $world = $this->world();
        $browser->loginUser($world['owner']);
        $path = $this->path($world['branch']);

        $list = $this->get($browser, $path.'?company_id='.$world['company']->id(), 200);
        self::assertCount(3, $list['incapacities']);
        self::assertSame(true, $list['synthetic']);
        self::assertSame(false, $list['persisted']);
        self::assertSame(false, $list['juridically_binding']);
        $record = $list['incapacities'][0];
        self::assertMatchesRegularExpression('/\Afixture-incapacity-[a-f0-9]{24}\z/D', $record['ref']);
        self::assertSame('SIMULADA', $record['state']);
        self::assertSame('2030-01-10', $record['starts_on']);
        self::assertFalse($record['automatically_approved']);
        foreach (['diagnosis', 'certificate', 'patient', 'medical_history', 'employee_id', 'employee_ref', 'salary'] as $privateField) {
            self::assertArrayNotHasKey($privateField, $record);
        }
        self::assertSame('nosniff', $browser->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame($record, $this->get($browser, $path.'/'.$record['ref'], 200)['incapacity']);
        self::assertSame(
            $list['incapacities'],
            $this->get($browser, $path.'?company_id='.$world['company']->id(), 200)['incapacities'],
        );
    }

    public function testOtherCompanyTenantBranchAndInvalidQueriesAreRejected(): void
    {
        $browser = static::createClient();
        $world = $this->world();
        $browser->loginUser($world['owner']);
        $path = $this->path($world['branch']);
        $own = $this->get($browser, $path, 200)['incapacities'][0]['ref'];
        $otherPath = $this->path($world['otherBranch']);
        $otherRef = $this->get($browser, $otherPath, 200)['incapacities'][0]['ref'];
        self::assertNotSame($own, $otherRef);

        $cases = [
            [$path.'?company_id='.$world['otherCompany']->id(), 404],
            [$path.'?company_id%5B%5D='.$world['company']->id(), 404],
            [$path.'?tenant_id='.$world['tenant']->id(), 400],
            [$path.'?unused=x', 400],
            [$path.'/invalid', 404],
            [$path.'/fixture-incapacity-'.str_repeat('0', 24), 404],
            [$otherPath.'/'.$own, 404],
            [$this->path($world['foreignBranch']), 404],
            ['/api/v1/branches/not-a-real-branch/hr/incapacities', 404],
        ];
        foreach ($cases as [$url, $status]) {
            $this->get($browser, $url, $status);
            self::assertStringNotContainsString(
                $world['foreignBranch']->id(),
                (string) $browser->getResponse()->getContent(),
            );
        }
    }

    public function testUnauthorizedRoleAndRealDataModeFailClosed(): void
    {
        $browser = static::createClient();
        $world = $this->world();
        $uri = $this->path($world['branch']);
        $browser->loginUser($world['restricted']);
        $this->get($browser, $uri, 403);

        $browser->loginUser($world['owner']);
        $previous = getenv('HR_REAL_DATA_ENABLED');
        putenv('HR_REAL_DATA_ENABLED=true');
        try {
            $this->get($browser, $uri, 404);
            $this->get($browser, $uri.'/fixture-incapacity-'.str_repeat('0', 24), 404);
        } finally {
            putenv($previous === false ? 'HR_REAL_DATA_ENABLED' : 'HR_REAL_DATA_ENABLED='.$previous);
        }
    }

    public function testServiceIsPureAndCannotCrossCompanyBoundaries(): void
    {
        $tenant = new Tenant('Ensayo original', 'in-scope-a');
        $outside = new Tenant('Ensayo externo', 'in-scope-b');
        $company = new LegalEntity($tenant, 'Empresa de laboratorio', null);
        $branch = new Branch($tenant, 'Sucursal inventada', 'original', $company);
        $foreignCompany = new LegalEntity($outside, 'Empresa fuera del ámbito', null);
        $catalog = new HrIncapacityCatalog();

        $items = $catalog->listFor($tenant, $company, $branch);
        self::assertCount(3, $items);
        self::assertSame($items, $catalog->listFor($tenant, $company, $branch));
        self::assertSame($items[0], $catalog->findFor($tenant, $company, $branch, $items[0]['ref']));
        self::assertNull($catalog->findFor($tenant, $company, $branch, 'unrecognized'));

        $this->expectException(DomainException::class);
        $catalog->listFor($tenant, $foreignCompany, $branch);
    }

    private function path(Branch $branch): string
    {
        return '/api/v1/branches/'.$branch->id().'/hr/incapacities';
    }

    /** @return array<string, mixed> */
    private function get(KernelBrowser $browser, string $uri, int $expected): array
    {
        $browser->request('GET', $uri);
        self::assertResponseStatusCodeSame($expected);

        return $expected === 200
            ? json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)
            : [];
    }

    /** @return array{tenant:Tenant,company:LegalEntity,otherCompany:LegalEntity,branch:Branch,otherBranch:Branch,foreignBranch:Branch,owner:User,restricted:User} */
    private function world(): array
    {
        $db = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $db);
        $salt = bin2hex(random_bytes(6));
        $tenant = new Tenant('Entidad ficticia de RRHH', 'inc-main-'.$salt);
        $outside = new Tenant('Ámbito externo ficticio', 'inc-other-'.$salt);
        $company = new LegalEntity($tenant, 'Empresa simulada', null, true);
        $otherCompany = new LegalEntity($tenant, 'Empresa alterna de prueba', null);
        $foreignCompany = new LegalEntity($outside, 'Entidad externa de prueba', null);
        $branch = new Branch($tenant, 'Sucursal local de prueba', 'principal', $company, true);
        $otherBranch = new Branch($tenant, 'Sucursal de otra empresa', 'separada', $otherCompany);
        $foreignBranch = new Branch($outside, 'Sucursal de otro tenant', 'extranjera', $foreignCompany);
        $owner = new User('inc-owner-'.$salt.'@example.test', 'Propietario ficticio');
        $restricted = new User('inc-limited-'.$salt.'@example.test', 'Operador sin acceso');
        $ownerMembership = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        $limitedMembership = new Membership($tenant, $restricted, 'ADMIN');
        $role = new Role($tenant, 'Acceso no RRHH', ['catalog.view']);

        foreach ([
            $tenant, $outside, $company, $otherCompany, $foreignCompany,
            $branch, $otherBranch, $foreignBranch,
            $owner, $restricted, $ownerMembership, $limitedMembership, $role,
        ] as $object) {
            $db->persist($object);
        }
        $db->persist(new BranchRoleAssignment($limitedMembership, $branch, $role));
        $db->flush();

        return compact('tenant', 'company', 'otherCompany', 'branch', 'otherBranch', 'foreignBranch', 'owner', 'restricted');
    }
}
