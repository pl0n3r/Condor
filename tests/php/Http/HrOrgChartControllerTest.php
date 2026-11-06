<?php
declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\HumanResources\HrOrgChartCatalog;
use App\Domain\Identity\Entity\{BranchRoleAssignment, Membership, Role, User};
use App\Domain\Organization\Entity\{Branch, LegalEntity, Tenant};
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HrOrgChartControllerTest extends WebTestCase
{
    public function testHttpScopeHierarchyAndInvalidInput(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $db);
        [$tenant, $company, $other, $branch, $second, $foreign, $owner] = $this->fixtures($db);
        $client->loginUser($owner);
        $url = '/api/v1/branches/'.$branch->id().'/hr/org-chart';
        $client->request('GET', $url.'?company_id='.$company->id());
        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([true, false, false], [$data['synthetic'], $data['persisted'], $data['juridically_binding']]);
        self::assertCount(4, $data['nodes']);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $parents = [];
        foreach ($data['nodes'] as $node) {
            self::assertTrue($node['synthetic']);
            self::assertArrayNotHasKey('employee_id', $node);
            $parents[$node['ref']] = $node['parent_ref'];
        }
        self::assertCount(4, $parents);
        foreach ($parents as $ref => $parent) {
            $seen = [$ref => true];
            while ($parent !== null) {
                self::assertArrayHasKey($parent, $parents);
                self::assertArrayNotHasKey($parent, $seen);
                $seen[$parent] = true;
                $parent = $parents[$parent];
            }
        }
        $ref = $data['nodes'][0]['ref'];
        $client->request('GET', $url.'/'.$ref);
        self::assertResponseStatusCodeSame(200);
        $detail = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($ref, $detail['node']['ref']);

        $otherUrl = '/api/v1/branches/'.$second->id().'/hr/org-chart';
        $client->request('GET', $otherUrl);
        self::assertResponseStatusCodeSame(200);
        $otherData = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotSame($ref, $otherData['nodes'][0]['ref']);
        foreach ([$otherUrl.'/'.$ref, $url.'?company_id='.$other->id(), $url.'/not-a-ref',
            $url.'/fixture-org-0000000000000000-direccion',
            '/api/v1/branches/'.$foreign->id().'/hr/org-chart',
            '/api/v1/branches/not-present/hr/org-chart',
            $url.'?company_id%5B%5D='.$company->id()] as $invalid) {
            $client->request('GET', $invalid);
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString($foreign->id(), (string) $client->getResponse()->getContent());
        }
        $client->request('GET', $url.'?tenant_id='.$tenant->id());
        self::assertResponseStatusCodeSame(400);
    }

    public function testPermissionAndRealDataFlagFailClosed(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $db);
        [, , , $branch, , , $owner, $restricted] = $this->fixtures($db);
        $url = '/api/v1/branches/'.$branch->id().'/hr/org-chart';
        $client->loginUser($restricted);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
        $client->loginUser($owner);
        $prior = getenv('HR_REAL_DATA_ENABLED');
        putenv('HR_REAL_DATA_ENABLED=true');
        try {
            foreach ([$url, $url.'/invalid-ref'] as $path) {
                $client->request('GET', $path);
                self::assertResponseStatusCodeSame(404);
            }
        } finally {
            putenv($prior === false ? 'HR_REAL_DATA_ENABLED' : 'HR_REAL_DATA_ENABLED='.$prior);
        }
    }

    public function testCatalogRejectsForeignCompany(): void
    {
        $a = new Tenant('Ficticio', 'org-a');
        $b = new Tenant('Ficticio externo', 'org-b');
        $company = new LegalEntity($a, 'Empresa A', null);
        $branch = new Branch($a, 'Sede A', 'sede-a', $company);
        self::assertCount(4, (new HrOrgChartCatalog())->listFor($a, $company, $branch));
        $this->expectException(DomainException::class);
        (new HrOrgChartCatalog())->findFor($a, new LegalEntity($b, 'Empresa B', null), $branch, 'invalid-ref');
    }

    /** @return array{Tenant,LegalEntity,LegalEntity,Branch,Branch,Branch,User,User} */
    private function fixtures(EntityManagerInterface $db): array
    {
        $suffix = bin2hex(random_bytes(5));
        $tenants = [
            new Tenant('Fixture de organigrama', 'chart-main-'.$suffix),
            new Tenant('Fixture de otra organización', 'chart-foreign-'.$suffix),
        ];
        $companies = [
            new LegalEntity($tenants[0], 'Compañía titular ficticia', null, true),
            new LegalEntity($tenants[0], 'Otra compañía ficticia', null),
            new LegalEntity($tenants[1], 'Compañía ajena ficticia', null),
        ];
        $branches = [
            new Branch($tenants[0], 'Oficina primera', 'primera', $companies[0], true),
            new Branch($tenants[0], 'Oficina segunda', 'segunda', $companies[1]),
            new Branch($tenants[1], 'Oficina ajena', 'ajena', $companies[2]),
        ];
        $users = [
            new User('chart-owner-'.$suffix.'@example.test', 'Owner ficticio'),
            new User('chart-limited-'.$suffix.'@example.test', 'Rol limitado ficticio'),
        ];
        $memberships = [
            new Membership($tenants[0], $users[0], Membership::ROLE_OWNER),
            new Membership($tenants[0], $users[1], 'ADMIN'),
        ];
        $noAccess = new Role($tenants[0], 'Sin permiso de personas', ['catalog.view']);
        foreach ([...$tenants, ...$companies, ...$branches, ...$users, ...$memberships, $noAccess] as $entity) {
            $db->persist($entity);
        }
        $db->persist(new BranchRoleAssignment($memberships[1], $branches[0], $noAccess));
        $db->flush();

        return [$tenants[0], $companies[0], $companies[1], $branches[0], $branches[1], $branches[2], $users[0], $users[1]];
    }
}
