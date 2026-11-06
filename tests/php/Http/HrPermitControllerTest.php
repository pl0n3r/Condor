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

/** End-to-end synthetic HTTP proof; all tenants, roles and branches are fictional. */
final class HrPermitControllerTest extends WebTestCase
{
    public function testReadOnlyPreviewBindsCompanyBranchAndReference(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $db);
        $world = $this->seedSyntheticWorld($db);
        [$tenant, $company, $secondaryCompany, $branch, $secondaryBranch, $foreignBranch, $owner] = $world;
        $client->loginUser($owner);

        $uri = sprintf('/api/v1/branches/%s/hr/permits', $branch->id());
        $client->request('GET', $uri.'?company_id='.$company->id());
        self::assertResponseStatusCodeSame(200);
        $reply = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['synthetic' => true, 'persisted' => false, 'juridically_binding' => false], [
            'synthetic' => $reply['synthetic'],
            'persisted' => $reply['persisted'],
            'juridically_binding' => $reply['juridically_binding'],
        ]);
        self::assertCount(3, $reply['permits']);
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $ref = $reply['permits'][0]['ref'];
        self::assertSame('PENDIENTE', $reply['permits'][0]['state']);
        self::assertFalse($reply['permits'][0]['automatically_approved']);
        self::assertArrayNotHasKey('employee_ref', $reply['permits'][0]);

        $client->request('GET', $uri.'/'.$ref.'?company_id='.$company->id());
        self::assertResponseStatusCodeSame(200);
        $detail = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($ref, $detail['permit']['ref']);
        self::assertFalse($detail['juridically_binding']);

        $alternate = sprintf('/api/v1/branches/%s/hr/permits', $secondaryBranch->id());
        $client->request('GET', $alternate);
        self::assertResponseStatusCodeSame(200);
        $rows = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotSame($ref, $rows['permits'][0]['ref']);

        foreach ([
            [$alternate.'/'.$ref, 404],
            [$uri.'?company_id='.$secondaryCompany->id(), 404],
            [$uri.'/'.$ref.'?company_id='.$secondaryCompany->id(), 404],
            [$uri.'?company_id%5B%5D='.$company->id(), 404],
            [$uri.'?tenant_id='.$tenant->id(), 400],
            [$uri.'/'.$ref.'?unexpected=1', 400],
            [$uri.'/invalid-ref', 404],
        ] as [$target, $expected]) {
            $client->request('GET', $target);
            self::assertResponseStatusCodeSame($expected);
        }

        $client->request('GET', '/api/v1/branches/'.$foreignBranch->id().'/hr/permits');
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString($foreignBranch->id(), (string) $client->getResponse()->getContent());
    }

    public function testPermissionAndRealDataToggleAreIndependentFailClosedGates(): void
    {
        $client = static::createClient();
        $database = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $database);
        $world = $this->seedSyntheticWorld($database);
        $uri = '/api/v1/branches/'.$world[3]->id().'/hr/permits';

        $client->loginUser($world[7]);
        $client->request('GET', $uri);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($world[6]);
        $saved = getenv('HR_REAL_DATA_ENABLED');
        putenv('HR_REAL_DATA_ENABLED=true');
        try {
            foreach ([$uri, $uri.'/fixture-permit-0000000000000000-personal'] as $target) {
                $client->request('GET', $target);
                self::assertResponseStatusCodeSame(404);
            }
        } finally {
            putenv($saved === false ? 'HR_REAL_DATA_ENABLED' : 'HR_REAL_DATA_ENABLED='.$saved);
        }
    }

    public function testCatalogNeverApprovesAndRejectsForeignCompany(): void
    {
        static::createClient();
        $db = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $db);
        [$tenant, $company, , $branch] = $this->seedSyntheticWorld($db);
        $subject = new HrPermitCatalog();
        $items = $subject->listFor($tenant, $company, $branch);
        self::assertCount(3, $items);
        self::assertSame($items, $subject->listFor($tenant, $company, $branch));
        self::assertSame($items[0], $subject->findFor($tenant, $company, $branch, $items[0]['ref']));
        self::assertNull($subject->findFor($tenant, $company, $branch, 'not-a-permit'));
        foreach ($items as $row) {
            self::assertTrue($row['synthetic']);
            self::assertFalse($row['automatically_approved']);
        }
        $alienTenant = new Tenant('Ámbito extranjero de prueba', 'alien-'.bin2hex(random_bytes(3)));
        $alienCompany = new LegalEntity($alienTenant, 'Empresa ficticia externa', null);
        $this->expectException(DomainException::class);
        $subject->listFor($tenant, $alienCompany, $branch);
    }

    /** @return array{Tenant,LegalEntity,LegalEntity,Branch,Branch,Branch,User,User} */
    private function seedSyntheticWorld(EntityManagerInterface $db): array
    {
        $tag = bin2hex(random_bytes(6));
        $tenants = [
            new Tenant('Tenant de permisos', 'permit-'.$tag),
            new Tenant('Tenant ajeno ficticio', 'outside-'.$tag),
        ];
        $legal = [
            new LegalEntity($tenants[0], 'Empresa primaria ficticia', null, true),
            new LegalEntity($tenants[0], 'Empresa alterna ficticia', null),
            new LegalEntity($tenants[1], 'Empresa fuera del tenant', null),
        ];
        $branches = [
            new Branch($tenants[0], 'Sede propia ficticia', 'own', $legal[0], true),
            new Branch($tenants[0], 'Sede alterna ficticia', 'alternate', $legal[1]),
            new Branch($tenants[1], 'Sede fuera del tenant', 'outside', $legal[2]),
        ];
        $people = [
            new User('permit-owner-'.$tag.'@example.test', 'Propietario de pruebas'),
            new User('permit-viewer-'.$tag.'@example.test', 'Operador sin acceso'),
        ];
        $memberships = [
            new Membership($tenants[0], $people[0], Membership::ROLE_OWNER),
            new Membership($tenants[0], $people[1], 'ADMIN'),
        ];
        $limitedRole = new Role($tenants[0], 'Sin permisos HR', ['catalog.view']);
        $entities = array_merge($tenants, $legal, $branches, $people, $memberships, [$limitedRole]);
        foreach ($entities as $entity) {
            $db->persist($entity);
        }
        $db->persist(new BranchRoleAssignment($memberships[1], $branches[0], $limitedRole));
        $db->flush();
        return [$tenants[0], $legal[0], $legal[1], $branches[0], $branches[1], $branches[2], $people[0], $people[1]];
    }
}
