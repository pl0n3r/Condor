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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Kernel-level M01 S03 contract: synthetic fixtures only, zero real people. */
final class HumanResourcesAreaControllerTest extends WebTestCase
{
    public function testHttpListingAndDetailsUseOnlyTheAuthorizedCompanyAndBranch(): void
    {
        $browser = static::createClient();
        $fixtures = $this->seed();
        $browser->loginUser($fixtures['owner']);

        $route = $this->endpoint($fixtures['branch']);
        $list = $this->load($browser, $route.'?company_id='.$fixtures['company']->id(), 200);
        self::assertTrue($list['synthetic']);
        self::assertFalse($list['persisted']);
        self::assertFalse($list['juridically_binding']);
        self::assertCount(3, $list['areas']);
        $first = $list['areas'][0];
        self::assertTrue($first['synthetic']);
        self::assertMatchesRegularExpression('/\Afixture-area-[a-f0-9]{24}\z/D', $first['ref']);
        self::assertStringNotContainsString($fixtures['tenant']->id(), $first['ref']);
        self::assertStringNotContainsString($fixtures['company']->id(), $first['ref']);
        self::assertStringNotContainsString($fixtures['branch']->id(), $first['ref']);
        $cache = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cache);
        self::assertStringContainsString('no-store', $cache);
        self::assertSame('nosniff', $browser->getResponse()->headers->get('X-Content-Type-Options'));

        $found = $this->load($browser, $route.'/'.$first['ref'], 200);
        self::assertSame($first, $found['area']);
        self::assertTrue($found['synthetic']);

        foreach (['otherCompanyBranch', 'sameCompanyBranch'] as $key) {
            $differentRoute = $this->endpoint($fixtures[$key]);
            $other = $this->load($browser, $differentRoute, 200);
            self::assertNotSame($first['ref'], $other['areas'][0]['ref']);
            $this->load($browser, $differentRoute.'/'.$first['ref'], 404);
        }
    }

    public function testHttpDeniesForeignScopesAndInvalidInputWithoutEnumeratingRecords(): void
    {
        $browser = static::createClient();
        $fixtures = $this->seed();
        $browser->loginUser($fixtures['owner']);
        $route = $this->endpoint($fixtures['branch']);

        foreach ([
            [$route.'?company_id='.$fixtures['otherCompany']->id(), 404],
            [$this->endpoint($fixtures['foreignBranch']), 404],
            [$route.'?tenant_id='.$fixtures['tenant']->id(), 400],
            [$route.'?company_id%5B%5D='.$fixtures['company']->id(), 404],
            [$route.'/unknown', 404],
            [$route.'/fixture-area-'.str_repeat('a', 25), 404],
        ] as [$url, $status]) {
            $this->load($browser, $url, $status);
            self::assertStringNotContainsString(
                $fixtures['foreignBranch']->id(),
                (string) $browser->getResponse()->getContent(),
            );
        }
    }

    public function testReadPermissionAndRealModeCannotExposePreview(): void
    {
        $browser = static::createClient();
        $fixtures = $this->seed();
        $route = $this->endpoint($fixtures['branch']);

        $browser->loginUser($fixtures['delegate']);
        $this->load($browser, $route, 403);

        $browser->loginUser($fixtures['owner']);
        $originalFlag = getenv('HR_REAL_DATA_ENABLED');
        putenv('HR_REAL_DATA_ENABLED=on');
        try {
            $this->load($browser, $route, 404);
            $this->load($browser, $route.'/fixture-area-'.str_repeat('a', 24), 404);
        } finally {
            putenv($originalFlag === false ? 'HR_REAL_DATA_ENABLED' : 'HR_REAL_DATA_ENABLED='.$originalFlag);
        }
    }

    public function testDomainRejectsCompanyTenantAndBranchMismatches(): void
    {
        $tenant = new Tenant('Ficticio A', 'area-a');
        $external = new Tenant('Ficticio B', 'area-b');
        $company = new LegalEntity($tenant, 'Entidad A', null);
        $foreignCompany = new LegalEntity($external, 'Entidad B', null);
        $branch = new Branch($tenant, 'Sede A', 'a', $company);
        $catalog = new AreaCatalog();
        self::assertCount(3, $catalog->listFor($tenant, $company, $branch));
        $this->expectException(DomainException::class);
        $catalog->findFor($tenant, $foreignCompany, $branch, 'invalid');
    }

    /** @return array<string,mixed> */
    private function load(KernelBrowser $browser, string $url, int $expected): array
    {
        $browser->request('GET', $url);
        self::assertResponseStatusCodeSame($expected);

        $text = (string) $browser->getResponse()->getContent();
        if ($expected !== 200) {
            return [];
        }

        $document = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        return $document;
    }

    private function endpoint(Branch $branch): string
    {
        return '/api/v1/branches/'.$branch->id().'/hr/areas';
    }

    /** @return array{tenant:Tenant,company:LegalEntity,otherCompany:LegalEntity,branch:Branch,otherCompanyBranch:Branch,sameCompanyBranch:Branch,foreignBranch:Branch,owner:User,delegate:User} */
    private function seed(): array
    {
        $database = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $database);
        $suffix = bin2hex(random_bytes(5));

        $tenant = new Tenant('A ficticio', 'area-a-'.$suffix);
        $foreign = new Tenant('B ficticio', 'area-b-'.$suffix);
        $company = new LegalEntity($tenant, 'Primera ficticia', null, true);
        $another = new LegalEntity($tenant, 'Segunda ficticia', null);
        $outside = new LegalEntity($foreign, 'Externa ficticia', null);
        $branch = new Branch($tenant, 'Principal', 'principal', $company, true);
        $second = new Branch($tenant, 'Otra empresa', 'otra', $another);
        $third = new Branch($tenant, 'Otra sede', 'segunda', $company);
        $external = new Branch($foreign, 'Externa', 'externa', $outside);

        $owner = new User('hr-area-owner-'.$suffix.'@example.test', 'Owner de prueba');
        $delegate = new User('hr-area-reader-'.$suffix.'@example.test', 'Reader de prueba');
        $membership = new Membership($tenant, $delegate, 'ADMIN');
        $limitedRole = new Role($tenant, 'Permisos de catálogo', ['catalog.view']);

        foreach ([
            $tenant, $foreign, $company, $another, $outside,
            $branch, $second, $third, $external, $owner, $delegate,
            new Membership($tenant, $owner, Membership::ROLE_OWNER), $membership, $limitedRole,
            new BranchRoleAssignment($membership, $branch, $limitedRole),
        ] as $entity) {
            $database->persist($entity);
        }
        $database->flush();

        return [
            'tenant' => $tenant,
            'company' => $company,
            'otherCompany' => $another,
            'branch' => $branch,
            'otherCompanyBranch' => $second,
            'sameCompanyBranch' => $third,
            'foreignBranch' => $external,
            'owner' => $owner,
            'delegate' => $delegate,
        ];
    }
}
