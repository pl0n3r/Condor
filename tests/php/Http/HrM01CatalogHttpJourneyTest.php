<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * #1799 / M01 S02–S11: ten real Symfony HTTP routes, one synthetic world.
 * No real worker identities, persistence operations, or legal approval.
 */
final class HrM01CatalogHttpJourneyTest extends WebTestCase
{
    private string|false $previousRealFlag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRealFlag = getenv('HR_REAL_DATA_ENABLED');
        putenv('HR_REAL_DATA_ENABLED=false');
    }

    protected function tearDown(): void
    {
        putenv($this->previousRealFlag === false
            ? 'HR_REAL_DATA_ENABLED'
            : 'HR_REAL_DATA_ENABLED='.$this->previousRealFlag);
        parent::tearDown();
    }

    /** @return array<string, array{string,string}> */
    private static function routes(): array
    {
        return [
            'S02' => ['positions', 'positions'],
            'S03' => ['areas', 'areas'],
            'S04' => ['branch-preview', 'branches'],
            'S05' => ['org-chart', 'nodes'],
            'S06' => ['changes', 'changes'],
            'S07' => ['permits', 'permits'],
            'S08' => ['vacations', 'vacations'],
            'S09' => ['incapacities', 'incapacities'],
            'S10' => ['evaluations', 'evaluations'],
            'S11' => ['employee-portal', 'items'],
        ];
    }

    public function testTenOwnerCatalogsAreSyntheticPrivateAndIsolated(): void
    {
        $http = static::createClient();
        $world = $this->world();
        $http->loginUser($world['owner']);

        foreach (self::routes() as $row => [$suffix, $key]) {
            $url = $this->url($world['branch'], $suffix);
            $payload = $this->get($http, $url.'?company_id='.$world['company']->id(), 200);
            self::assertSame(true, $payload['synthetic'] ?? null, $row);
            self::assertSame(false, $payload['persisted'] ?? null, $row);
            self::assertSame(false, $payload['juridically_binding'] ?? null, $row);
            self::assertIsArray($payload[$key] ?? null, $row);
            self::assertNotEmpty($payload[$key], $row);
            self::assertIsString($payload[$key][0]['ref'] ?? null, $row);
            $reference = $payload[$key][0]['ref'];

            self::assertStringContainsString('private', (string) $http->getResponse()->headers->get('Cache-Control'), $row);
            self::assertStringContainsString('no-store', (string) $http->getResponse()->headers->get('Cache-Control'), $row);
            self::assertSame('nosniff', $http->getResponse()->headers->get('X-Content-Type-Options'), $row);
            self::assertStringNotContainsString($world['tenant']->id(), $reference, $row);
            self::assertStringNotContainsString($world['company']->id(), $reference, $row);

            $otherUrl = $this->url($world['otherBranch'], $suffix);
            $other = $this->get($http, $otherUrl, 200);
            self::assertIsString($other[$key][0]['ref'] ?? null, $row);
            self::assertNotSame($reference, $other[$key][0]['ref'], $row);
            $this->get($http, $otherUrl.'/'.$reference, 404);
            $this->assertNoForeignReferences($http, $world);
        }
    }

    public function testTenCatalogsDenyUnprivilegedAndCrossTenantCompanyAccess(): void
    {
        $http = static::createClient();
        $world = $this->world();
        $http->loginUser($world['owner']);

        foreach (self::routes() as $row => [$suffix]) {
            $url = $this->url($world['branch'], $suffix);
            $this->get($http, $url.'?company_id='.$world['otherCompany']->id(), 404);
            $this->assertNoForeignReferences($http, $world);
            $this->get($http, $this->url($world['foreignBranch'], $suffix), 404);
            $this->assertNoForeignReferences($http, $world);
        }

        $http->loginUser($world['viewer']);
        foreach (self::routes() as $row => [$suffix]) {
            $this->get($http, $this->url($world['branch'], $suffix), 403);
            $this->assertNoForeignReferences($http, $world);
        }
    }

    public function testRealDataFlagFailsClosedForAllTenCatalogs(): void
    {
        $http = static::createClient();
        $world = $this->world();
        $http->loginUser($world['owner']);
        putenv('HR_REAL_DATA_ENABLED=true');
        foreach (self::routes() as $row => [$suffix]) {
            $this->get($http, $this->url($world['branch'], $suffix), 404);
            $this->assertNoForeignReferences($http, $world);
        }
    }

    private function url(Branch $branch, string $route): string
    {
        return '/api/v1/branches/'.$branch->id().'/hr/'.$route;
    }

    /** @return array<string,mixed> */
    private function get(KernelBrowser $http, string $url, int $status): array
    {
        $http->request('GET', $url);
        self::assertResponseStatusCodeSame($status, $url);
        if ($status !== 200) {
            return [];
        }
        $data = json_decode((string) $http->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    /** @param array<string,mixed> $world */
    private function assertNoForeignReferences(KernelBrowser $http, array $world): void
    {
        $body = (string) $http->getResponse()->getContent();
        self::assertStringNotContainsString($world['foreignBranch']->id(), $body);
        self::assertStringNotContainsString($world['otherCompany']->id(), $body);
    }

    /** @return array{tenant:Tenant,company:LegalEntity,otherCompany:LegalEntity,branch:Branch,otherBranch:Branch,foreignBranch:Branch,owner:User,viewer:User} */
    private function world(): array
    {
        $db = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $db);
        $id = bin2hex(random_bytes(5));
        $tenant = new Tenant('Tenant sintético A', 'hr-journey-a-'.$id);
        $foreign = new Tenant('Tenant sintético B', 'hr-journey-b-'.$id);
        $company = new LegalEntity($tenant, 'Empresa sintética A', null, true);
        $otherCompany = new LegalEntity($tenant, 'Empresa sintética B', null);
        $foreignCompany = new LegalEntity($foreign, 'Empresa sintética C', null);
        $branch = new Branch($tenant, 'Sede de prueba A', 'sede-a', $company, true);
        $otherBranch = new Branch($tenant, 'Sede de prueba B', 'sede-b', $otherCompany);
        $foreignBranch = new Branch($foreign, 'Sede ajena', 'sede-c', $foreignCompany);
        $owner = new User('hr-journey-owner-'.$id.'@example.test', 'Dueño sintético');
        $viewer = new User('hr-journey-viewer-'.$id.'@example.test', 'Consulta limitada');
        $member = new Membership($tenant, $viewer, 'ADMIN');
        $role = new Role($tenant, 'Solo catálogo', ['catalog.view']);
        foreach ([
            $tenant, $foreign, $company, $otherCompany, $foreignCompany,
            $branch, $otherBranch, $foreignBranch, $owner, $viewer,
            new Membership($tenant, $owner, Membership::ROLE_OWNER),
            $member, $role, new BranchRoleAssignment($member, $branch, $role),
        ] as $entity) {
            $db->persist($entity);
        }
        $db->flush();
        return compact('tenant', 'company', 'otherCompany', 'branch', 'otherBranch', 'foreignBranch', 'owner', 'viewer');
    }
}
