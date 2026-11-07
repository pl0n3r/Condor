<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\HumanResources\HrPortalCatalog;
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

/** HTTP real con identidades y sedes fabricadas, sin acceso a registros personales. */
final class HrPortalControllerTest extends WebTestCase
{
    public function testOwnerSeesPrivateReadOnlyFixtureListAndDetail(): void
    {
        [$browser, $actors] = $this->browserWithActors();
        $company = $actors[1];
        $endpoint = $this->portalPath($actors[3]);
        $browser->request('GET', $endpoint.'?company_id='.$company->id());
        self::assertResponseStatusCodeSame(200);
        $response = json_decode((string) $browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(3, $response['items']);
        self::assertTrue($response['synthetic']);
        self::assertFalse($response['persisted'] || $response['juridically_binding']);
        self::assertSame('SOLO_LECTURA', $response['items'][0]['state']);
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));

        $record = $response['items'][1];
        $browser->request('GET', $endpoint.'/'.$record['ref']);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString($record['ref'], (string) $browser->getResponse()->getContent());
        $browser->request('POST', $endpoint);
        self::assertResponseStatusCodeSame(405);
    }

    public function testOtherBranchOtherCompanyAndOtherTenantCannotBeEnumerated(): void
    {
        [$client, $f] = $this->browserWithActors();
        $target = $this->portalPath($f[3]);
        $client->request('GET', $target);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $reference = $payload['items'][0]['ref'];

        $nearby = $this->portalPath($f[4]);
        $client->request('GET', $nearby.'/'.$reference);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $target.'?company_id='.$f[2]->id());
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $this->portalPath($f[5]));
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString($f[5]->id(), (string) $client->getResponse()->getContent());

        $client->request('GET', $target.'/fixture-hr-portal-0000000000000000-avisos');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $target.'/not-a-fixture');
        self::assertResponseStatusCodeSame(404);
    }

    public function testQueryValidationRejectsUnknownAndArrayParameters(): void
    {
        [$browser, $scope] = $this->browserWithActors();
        $url = $this->portalPath($scope[3]);
        $browser->request('GET', $url.'?tenant_id=extra');
        self::assertResponseStatusCodeSame(400);
        $browser->request('GET', $url.'?company_id%5B%5D='.$scope[1]->id());
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousAndViewerAreBothDenied(): void
    {
        [$client, $actors] = $this->browserWithActors(false);
        $url = $this->portalPath($actors[3]);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(401);
        $client->loginUser($actors[7]);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
    }

    public function testEnablingRealPersonnelFlagClosesPortal(): void
    {
        [$browser, $scope] = $this->browserWithActors();
        $prior = getenv('HR_REAL_DATA_ENABLED');
        putenv('HR_REAL_DATA_ENABLED=1');
        try {
            foreach ([$this->portalPath($scope[3]), $this->portalPath($scope[3]).'/irrelevant'] as $url) {
                $browser->request('GET', $url);
                self::assertResponseStatusCodeSame(404);
            }
        } finally {
            $prior === false ? putenv('HR_REAL_DATA_ENABLED') : putenv('HR_REAL_DATA_ENABLED='.$prior);
        }
    }

    public function testCatalogRejectsCompanyOutsideItsTenant(): void
    {
        $tenant = new Tenant('Ámbito ficticio', 'portal-a');
        $foreign = new Tenant('Ámbito externo', 'portal-b');
        $company = new LegalEntity($tenant, 'Compañía demo', null);
        $branch = new Branch($tenant, 'Sede demo', 'demo', $company);
        self::assertCount(3, (new HrPortalCatalog())->listFor($tenant, $company, $branch));
        $this->expectException(DomainException::class);
        (new HrPortalCatalog())->listFor($tenant, new LegalEntity($foreign, 'Ajena demo', null), $branch);
    }

    private function portalPath(Branch $branch): string
    {
        return '/api/v1/branches/'.$branch->id().'/hr/employee-portal';
    }

    /** @return array{KernelBrowser,array{Tenant,LegalEntity,LegalEntity,Branch,Branch,Branch,User,User}} */
    private function browserWithActors(bool $authenticate = true): array
    {
        $client = static::createClient();
        $db = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $db);
        $actors = $this->fixtures($db);
        if ($authenticate) {
            $client->loginUser($actors[6]);
        }

        return [$client, $actors];
    }

    /** @return array{Tenant,LegalEntity,LegalEntity,Branch,Branch,Branch,User,User} */
    private function fixtures(EntityManagerInterface $db): array
    {
        $suffix = bin2hex(random_bytes(5));
        $tenant = new Tenant('Portal principal', 'portal-main-'.$suffix);
        $external = new Tenant('Portal externo', 'portal-foreign-'.$suffix);
        $company = new LegalEntity($tenant, 'Empresa de muestra', null, true);
        $secondary = new LegalEntity($tenant, 'Otra entidad de muestra', null);
        $externalCompany = new LegalEntity($external, 'Empresa ajena de muestra', null);
        $branch = new Branch($tenant, 'Sede principal', 'principal', $company, true);
        $secondaryBranch = new Branch($tenant, 'Sede secundaria', 'secundaria', $secondary);
        $externalBranch = new Branch($external, 'Sede ajena', 'ajena', $externalCompany);
        $owner = new User('portal-owner-'.$suffix.'@example.test', 'Titular ficticio');
        $reader = new User('portal-limited-'.$suffix.'@example.test', 'Lector ficticio');
        $adminMember = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        $readerMember = new Membership($tenant, $reader, 'ADMIN');
        $viewerRole = new Role($tenant, 'Lector limitado', ['catalog.view']);
        foreach ([$tenant, $external, $company, $secondary, $externalCompany,
            $branch, $secondaryBranch, $externalBranch, $owner, $reader,
            $adminMember, $readerMember, $viewerRole] as $entity) {
            $db->persist($entity);
        }
        $db->persist(new BranchRoleAssignment($readerMember, $branch, $viewerRole));
        $db->flush();

        return [$tenant, $company, $secondary, $branch, $secondaryBranch, $externalBranch, $owner, $reader];
    }
}
