<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\HumanResources\HrBranchCatalog;
use App\Domain\Identity\Entity\{BranchRoleAssignment, Membership, Role, User};
use App\Domain\Organization\Entity\{Branch, LegalEntity, Tenant};
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HrBranchControllerTest extends WebTestCase
{
    public function testSyntheticHttpCatalogAndDetailRespectTheAuthorizedScope(): void
    {
        $http = static::createClient();
        $db = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $db);
        $f = $this->seed($db);
        $http->loginUser($f['owner']);
        $url = '/api/v1/branches/'.$f['branch']->id().'/hr/branch-preview';

        $http->request('GET', $url.'?company_id='.$f['company']->id());
        self::assertResponseIsSuccessful();
        $list = json_decode((string) $http->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(3, $list['branches']);
        self::assertTrue($list['synthetic']);
        self::assertFalse($list['persisted']);
        self::assertFalse($list['juridically_binding']);
        self::assertSame('nosniff', $http->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('private', (string) $http->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $http->getResponse()->headers->get('Cache-Control'));
        $ref = $list['branches'][0]['ref'];

        $http->request('GET', $url.'/'.$ref);
        self::assertResponseIsSuccessful();
        $record = json_decode((string) $http->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($ref, $record['branch']['ref']);
        self::assertTrue($record['branch']['synthetic']);
        self::assertStringContainsString('no-store', (string) $http->getResponse()->headers->get('Cache-Control'));

        $secondary = '/api/v1/branches/'.$f['secondary']->id().'/hr/branch-preview';
        $http->request('GET', $secondary);
        self::assertResponseIsSuccessful();
        $other = json_decode((string) $http->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotSame($ref, $other['branches'][0]['ref']);

        foreach ([
            [$secondary.'/'.$ref, 404],
            [$url.'?company_id='.$f['anotherCompany']->id(), 404],
            [$url.'?company_id%5B%5D='.$f['company']->id(), 404],
            [$url.'?tenant_id='.$f['tenant']->id(), 400],
            [$url.'/bad-ref', 404],
            [$url.'/'.str_repeat('z', 81), 404],
            ['/api/v1/branches/'.$f['foreign']->id().'/hr/branch-preview', 404],
            ['/api/v1/branches/missing-fixture/hr/branch-preview', 404],
        ] as [$target, $status]) {
            $http->request('GET', $target);
            self::assertResponseStatusCodeSame($status);
            self::assertStringNotContainsString($f['foreign']->id(), (string) $http->getResponse()->getContent());
        }
    }

    public function testDeniedRoleAndRealDataFlagBlockBothPreviewRoutes(): void
    {
        $http = static::createClient();
        $db = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $db);
        $f = $this->seed($db);
        $url = '/api/v1/branches/'.$f['branch']->id().'/hr/branch-preview';

        $http->loginUser($f['guest']);
        $http->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        $http->loginUser($f['owner']);
        $before = getenv('HR_REAL_DATA_ENABLED');
        putenv('HR_REAL_DATA_ENABLED=on');
        try {
            foreach ([$url, $url.'/fixture-branch-0000000000000000-norte'] as $target) {
                $http->request('GET', $target);
                self::assertResponseStatusCodeSame(404);
            }
        } finally {
            putenv($before === false ? 'HR_REAL_DATA_ENABLED' : 'HR_REAL_DATA_ENABLED='.$before);
        }
    }

    public function testCatalogReferencesAreOpaqueAndBoundToThreePartScope(): void
    {
        $tenant = new Tenant('Demo A', 'synthetic-a');
        $externalTenant = new Tenant('Demo B', 'synthetic-b');
        $company = new LegalEntity($tenant, 'Empresa prueba A', null);
        $secondaryCompany = new LegalEntity($tenant, 'Empresa prueba C', null);
        $alienCompany = new LegalEntity($externalTenant, 'Empresa prueba B', null);
        $branch = new Branch($tenant, 'Origen', 'origen', $company);
        $secondary = new Branch($tenant, 'Sucursal', 'sucursal', $secondaryCompany);
        $alien = new Branch($externalTenant, 'Ajena', 'ajena', $alienCompany);
        $catalog = new HrBranchCatalog();

        $samples = $catalog->listFor($tenant, $company, $branch);
        self::assertSame($samples, $catalog->listFor($tenant, $company, $branch));
        self::assertCount(3, $samples);
        $ref = $samples[0]['ref'];
        self::assertMatchesRegularExpression('/\Afixture-branch-[a-f0-9]{16}-[a-z]+\z/D', $ref);
        self::assertSame($samples[0], $catalog->findFor($tenant, $company, $branch, $ref));
        self::assertNull($catalog->findFor($tenant, $company, $branch, 'incorrect'));
        self::assertNull($catalog->findFor($tenant, $company, $branch, str_repeat('n', 81)));

        $secondaryRef = $catalog->listFor($tenant, $secondaryCompany, $secondary)[0]['ref'];
        $externalRef = $catalog->listFor($externalTenant, $alienCompany, $alien)[0]['ref'];
        self::assertNull($catalog->findFor($tenant, $company, $branch, $secondaryRef));
        self::assertNull($catalog->findFor($tenant, $company, $branch, $externalRef));

        $this->expectException(DomainException::class);
        $catalog->listFor($tenant, $alienCompany, $branch);
    }

    /** @return array{owner:User,guest:User,tenant:Tenant,company:LegalEntity,anotherCompany:LegalEntity,branch:Branch,secondary:Branch,foreign:Branch} */
    private function seed(EntityManagerInterface $db): array
    {
        $suffix = bin2hex(random_bytes(5));
        $tenant = new Tenant('Ensayo principal', 'branch-test-'.$suffix);
        $externalTenant = new Tenant('Ensayo externo', 'branch-external-'.$suffix);

        $companies = [
            'primary' => new LegalEntity($tenant, 'Entidad de prueba uno', null, true),
            'secondary' => new LegalEntity($tenant, 'Entidad de prueba dos', null),
            'external' => new LegalEntity($externalTenant, 'Entidad ficticia externa', null),
        ];
        $company = $companies['primary'];
        $anotherCompany = $companies['secondary'];
        $sites = [
            'main' => new Branch($tenant, 'Oficina de ensayo uno', 'oficina-uno', $company, true),
            'secondary' => new Branch($tenant, 'Oficina de ensayo dos', 'oficina-dos', $anotherCompany),
            'other' => new Branch($externalTenant, 'Oficina ajena', 'oficina-ajena', $companies['external']),
        ];
        $branch = $sites['main'];
        $secondary = $sites['secondary'];
        $foreign = $sites['other'];

        $people = [
            'owner' => new User('branch-owner-'.$suffix.'@example.test', 'Usuario ficticio autorizado'),
            'guest' => new User('branch-guest-'.$suffix.'@example.test', 'Usuario ficticio limitado'),
        ];
        $owner = $people['owner'];
        $guest = $people['guest'];
        $members = [
            'owner' => new Membership($tenant, $owner, Membership::ROLE_OWNER),
            'guest' => new Membership($tenant, $guest, 'ADMIN'),
        ];
        $role = new Role($tenant, 'Lectura de catálogo', ['catalog.view']);
        foreach ([$tenant, $externalTenant, ...array_values($companies), ...array_values($sites), ...array_values($people), ...array_values($members), $role] as $entity) {
            $db->persist($entity);
        }
        $db->persist(new BranchRoleAssignment($members['guest'], $branch, $role));
        $db->flush();

        return compact('owner', 'guest', 'tenant', 'company', 'anotherCompany', 'branch', 'secondary', 'foreign');
    }
}
