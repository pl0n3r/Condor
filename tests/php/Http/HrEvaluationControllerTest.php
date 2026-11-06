<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\HumanResources\HrEvaluationCatalog;
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

/** Exact M01 S10 HTTP permission and isolation test on fictional entities. */
final class HrEvaluationControllerTest extends WebTestCase
{
    public function testOwnerGetsOnlyHumanReviewedAndUnrankedDemonstrations(): void
    {
        $browser = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $world = $this->seedWorld($manager);
        $browser->loginUser($world['owner']);

        $url = $this->urlFor($world['branch']);
        $browser->request('GET', $url.'?company_id='.$world['company']->id());
        self::assertResponseStatusCodeSame(200);
        $body = $this->readJson($browser);
        self::assertTrue($body['synthetic']);
        self::assertFalse($body['persisted']);
        self::assertFalse($body['juridically_binding']);
        self::assertCount(3, $body['evaluations']);

        $cache = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cache);
        self::assertStringContainsString('no-store', $cache);

        foreach ($body['evaluations'] as $case) {
            self::assertTrue($case['synthetic']);
            self::assertNull($case['score']);
            self::assertTrue($case['needs_human_review']);
            self::assertFalse($case['automatically_approved']);
            self::assertFalse($case['automatically_closed']);
            self::assertArrayNotHasKey('ranking', $case);
            self::assertStringStartsWith('fixture:employee/', $case['employee_ref']);
            self::assertStringStartsWith('fixture:employee/', $case['evaluator_ref']);
            self::assertStringStartsWith('fixture:cycle/', $case['cycle_ref']);
        }

        $reference = $body['evaluations'][0]['ref'];
        $browser->request('GET', $url.'/'.$reference);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($reference, $this->readJson($browser)['evaluation']['ref']);

        $browser->request('GET', $this->urlFor($world['second']));
        self::assertResponseStatusCodeSame(200);
        $differentRef = $this->readJson($browser)['evaluations'][0]['ref'];
        self::assertNotSame($reference, $differentRef);
        $browser->request('GET', $this->urlFor($world['second']).'/'.$reference);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnauthorizedCompanyAndForeignTenantNeverDiscloseReferences(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $world = $this->seedWorld($manager);
        $client->loginUser($world['owner']);
        $url = $this->urlFor($world['branch']);

        $cases = [
            [$url.'?company_id='.$world['alternate']->id(), 404],
            [$this->urlFor($world['foreign']), 404],
            [$url.'?tenant_id='.$world['tenant']->id(), 400],
            [$url.'?company_id%5B%5D='.$world['company']->id(), 404],
            [$url.'/fixture-evaluation-0000000000000000-jefe-revision', 404],
            [$url.'/invalid-ref', 404],
            [$url.'?unexpected=1', 400],
        ];

        foreach ($cases as [$address, $expected]) {
            $client->request('GET', $address);
            self::assertResponseStatusCodeSame($expected);
            self::assertStringNotContainsString(
                $world['foreign']->id(), (string) $client->getResponse()->getContent(),
            );
        }

        $client->loginUser($world['viewer']);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', $url.'/anything');
        self::assertResponseStatusCodeSame(403);
    }

    public function testRealDataModeClosesBothDemonstrationRoutes(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $world = $this->seedWorld($manager);
        $client->loginUser($world['owner']);

        $previous = getenv('HR_REAL_DATA_ENABLED');
        putenv('HR_REAL_DATA_ENABLED=true');
        try {
            $url = $this->urlFor($world['branch']);
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', $url.'/anything');
            self::assertResponseStatusCodeSame(404);
        } finally {
            putenv($previous === false ? 'HR_REAL_DATA_ENABLED' : 'HR_REAL_DATA_ENABLED='.$previous);
        }
    }

    public function testServiceRefusesForeignCompanyBeforeResolvingReference(): void
    {
        $tenant = new Tenant('Prueba de empresa A', 'eval-scope-a');
        $stranger = new Tenant('Prueba de empresa B', 'eval-scope-b');
        $company = new LegalEntity($tenant, 'Solo prueba', null);
        $alien = new LegalEntity($stranger, 'Sin acceso', null);
        $branch = new Branch($tenant, 'Local', 'local', $company);
        $catalog = new HrEvaluationCatalog();
        $entries = $catalog->listFor($tenant, $company, $branch);
        self::assertCount(3, $entries);
        self::assertNull($catalog->findFor($tenant, $company, $branch, 'unknown'));

        $this->expectException(DomainException::class);
        $catalog->findFor($tenant, $alien, $branch, $entries[0]['ref']);
    }

    private function urlFor(Branch $branch): string
    {
        return '/api/v1/branches/'.$branch->id().'/hr/evaluations';
    }

    /** @return array<string, mixed> */
    private function readJson(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array{tenant: Tenant, company: LegalEntity, alternate: LegalEntity,
     * branch: Branch, second: Branch, foreign: Branch, owner: User, viewer: User}
     */
    private function seedWorld(EntityManagerInterface $db): array
    {
        $suffix = bin2hex(random_bytes(5));
        $tenant = new Tenant('Empresa principal ficticia', 'eval-main-'.$suffix);
        $unrelated = new Tenant('Empresa externa ficticia', 'eval-other-'.$suffix);
        $company = new LegalEntity($tenant, 'Razón principal de ensayo', null, true);
        $alternate = new LegalEntity($tenant, 'Otra razón de ensayo', null);
        $foreignCompany = new LegalEntity($unrelated, 'Razón ajena de ensayo', null);
        $branch = new Branch($tenant, 'Evaluaciones', 'evaluaciones', $company, true);
        $second = new Branch($tenant, 'Sucursal de ensayo', 'sucursal', $alternate);
        $foreign = new Branch($unrelated, 'Sede externa', 'externa', $foreignCompany);
        $owner = new User('eval-owner-'.$suffix.'@example.test', 'Dueño ficticio');
        $viewer = new User('eval-viewer-'.$suffix.'@example.test', 'Sin permisos RR. HH.');
        $ownerMembership = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        $viewerMembership = new Membership($tenant, $viewer, 'ADMIN');
        $readCatalog = new Role($tenant, 'Catálogo simple', ['catalog.view']);

        foreach ([
            $tenant, $unrelated, $company, $alternate, $foreignCompany,
            $branch, $second, $foreign, $owner, $viewer,
            $ownerMembership, $viewerMembership, $readCatalog,
        ] as $fixture) {
            $db->persist($fixture);
        }
        $db->persist(new BranchRoleAssignment($viewerMembership, $branch, $readCatalog));
        $db->flush();

        return compact('tenant', 'company', 'alternate', 'branch', 'second', 'foreign', 'owner', 'viewer');
    }
}
