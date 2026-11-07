<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\SocialSecurity\SocialSecurityApiPort;
use App\Application\SocialSecurity\SocialSecurityApiService;
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

/** #1707: M05-S01 HTTP real con ledger descartable; S02-S06 no exponen operaciones externas. */
final class GestM05SyntheticHttpJourneyTest extends WebTestCase
{
    private string|false $previousReal;
    private string|false $previousSynthetic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousReal = getenv('SOCIAL_SECURITY_REAL_DATA_ENABLED');
        $this->previousSynthetic = getenv('CONDOR_SOCIAL_SECURITY_SYNTHETIC_API');
        putenv('SOCIAL_SECURITY_REAL_DATA_ENABLED=false');
        putenv('CONDOR_SOCIAL_SECURITY_SYNTHETIC_API=0');
    }

    protected function tearDown(): void
    {
        foreach ([
            'SOCIAL_SECURITY_REAL_DATA_ENABLED' => $this->previousReal,
            'CONDOR_SOCIAL_SECURITY_SYNTHETIC_API' => $this->previousSynthetic,
        ] as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
        parent::tearDown();
    }

    public function testOwnerCanExerciseFourFixtureRoutesWithoutRealData(): void
    {
        $http = static::createClient();
        // El ledger de prueba vive en el contenedor durante todo el recorrido HTTP.
        $http->disableReboot();
        $world = $this->world();
        $this->installFakeLedger();
        $base = $this->base($world['branch']);
        $http->loginUser($world['owner']);
        $this->get($http, $base, 200);
        self::assertSame([], $this->json($http)['affiliations']);
        $csrf = $this->csrf($http);

        $create = [
            'affiliation_ref' => 'fixture:affiliation/demom05',
            'employee_ref' => 'fixture:employee/demom05',
            'plan_ref' => 'fixture:plan/demom05',
        ];
        $http->jsonRequest('POST', $base, $create, ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('BORRADOR', $this->json($http)['affiliation']['state']);
        $this->assertPrivateResponse($http);

        $this->get($http, $base.'/demom05', 200);
        self::assertSame('fixture:affiliation/demom05', $this->json($http)['affiliation']['affiliation_ref']);
        $http->jsonRequest('PATCH', $base.'/demom05/state', [
            'state' => 'EN_REVISION', 'expected_version' => 1,
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $this->json($http)['affiliation']['version']);
        self::assertSame('EN_REVISION', $this->json($http)['affiliation']['state']);

        $this->get($http, $this->base($world['otherBranch']), 200);
        self::assertSame([], $this->json($http)['affiliations']);
        $this->get($http, $this->base($world['foreignBranch']), 404);
        self::assertStringNotContainsString('fixture:affiliation/demom05',
            (string) $http->getResponse()->getContent());
    }

    public function testGuestMissingPermissionAndNonOwnerCannotReadOrWrite(): void
    {
        $http = static::createClient();
        // El ledger de prueba vive en el contenedor durante todo el recorrido HTTP.
        $http->disableReboot();
        $world = $this->world();
        $this->installFakeLedger();
        $base = $this->base($world['branch']);

        $http->request('GET', $base);
        self::assertContains($http->getResponse()->getStatusCode(), [401, 403]);
        $http->loginUser($world['viewer']);
        $this->get($http, $base, 403);
        $http->loginUser($world['delegate']);
        $this->get($http, $base, 403);
        $http->jsonRequest('POST', $base, ['affiliation_ref' => 'fixture:affiliation/demo']);
        self::assertResponseStatusCodeSame(403);
        $http->request('PATCH', $base.'/demo/state');
        self::assertResponseStatusCodeSame(403);
        $http->loginUser($world['owner']);
        $this->get($http, $this->base($world['foreignBranch']), 404);
    }

    public function testCsrfInvalidReferencesAndFieldsFailClosed(): void
    {
        $http = static::createClient();
        // El ledger de prueba vive en el contenedor durante todo el recorrido HTTP.
        $http->disableReboot();
        $world = $this->world();
        $this->installFakeLedger();
        $http->loginUser($world['owner']);
        $base = $this->base($world['branch']);
        $valid = [
            'affiliation_ref' => 'fixture:affiliation/solo',
            'employee_ref' => 'fixture:employee/solo',
            'plan_ref' => 'fixture:plan/solo',
        ];

        $http->jsonRequest('POST', $base, $valid);
        self::assertResponseStatusCodeSame(403);
        $http->jsonRequest('PATCH', $base.'/solo/state', [
            'state' => 'EN_REVISION', 'expected_version' => 1,
        ]);
        self::assertResponseStatusCodeSame(403);
        $csrf = $this->csrf($http);

        foreach ([
            $valid + ['nombre_real' => 'Prohibido'],
            array_replace($valid, ['employee_ref' => 'cedula:12345678']),
            array_replace($valid, ['plan_ref' => 'fixture:plan/123456789']),
            array_replace($valid, ['affiliation_ref' => 'https://example.test/secret']),
        ] as $invalid) {
            $http->jsonRequest('POST', $base, $invalid, ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(422);
        }
        $http->jsonRequest('PATCH', $base.'/solo/state', [
            'state' => 'PAGADO', 'expected_version' => 1,
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(422);
        $this->get($http, $base, 200);
        self::assertSame([], $this->json($http)['affiliations']);
    }

    public function testOtherSlicesHaveNoUnsafeEndpointsAndFlagOffKeepsRealDataClosed(): void
    {
        $http = static::createClient();
        // El ledger de prueba vive en el contenedor durante todo el recorrido HTTP.
        $http->disableReboot();
        $world = $this->world();
        $this->installFakeLedger();
        $http->loginUser($world['owner']);
        $root = '/api/v1/branches/'.$world['branch']->id().'/social-security';
        $this->get($http, $root.'/affiliations', 200); // kernel test permite fixtures aun con flag DEV apagado
        foreach (['novelties', 'contributions', 'supports', 'alerts', 'pila'] as $route) {
            $this->get($http, $root.'/'.$route, 404);
            // Ni siquiera los verbos de escritura deben aparecer en S02–S06.
            $http->jsonRequest('POST', $root.'/'.$route, ['fixture' => 'solo_test']);
            self::assertResponseStatusCodeSame(404, $route);
        }
        $this->get($http, $root.'/records', 404);
        self::assertSame('feature_unavailable', $this->json($http)['error']);
        $http->jsonRequest('POST', $root.'/records', ['record_ref' => 'demo']);
        self::assertResponseStatusCodeSame(404);
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $db = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $db);
        $id = substr(bin2hex(random_bytes(6)), 0, 5).'a'.substr(bin2hex(random_bytes(6)), 0, 5);
        $tenant = new Tenant('M05 sintético A', 'm05a-'.$id);
        $foreign = new Tenant('M05 sintético B', 'm05b-'.$id);
        $company = new LegalEntity($tenant, 'M05 Empresa A', null, true);
        $otherCompany = new LegalEntity($tenant, 'M05 Empresa B', null);
        $foreignCompany = new LegalEntity($foreign, 'M05 Empresa C', null, true);
        $branch = new Branch($tenant, 'Sede M05 A', 'sedea', $company, true);
        $otherBranch = new Branch($tenant, 'Sede M05 B', 'sedeb', $otherCompany);
        $foreignBranch = new Branch($foreign, 'Sede externa', 'sedec', $foreignCompany);
        $owner = new User('m05-owner-'.$id.'@example.test', 'Dueño sintético');
        $viewer = new User('m05-viewer-'.$id.'@example.test', 'Usuario sin permiso');
        $delegate = new User('m05-delegate-'.$id.'@example.test', 'Usuario no owner');
        $member = new Membership($tenant, $viewer, 'ADMIN');
        $delegatedMember = new Membership($tenant, $delegate, 'ADMIN');
        $role = new Role($tenant, 'M05 lectura sintética', ['users.view']);
        $noRole = new Role($tenant, 'M05 sin consulta', ['catalog.view']);
        foreach ([
            $tenant, $foreign, $company, $otherCompany, $foreignCompany,
            $branch, $otherBranch, $foreignBranch, $owner, $viewer, $delegate,
            new Membership($tenant, $owner, Membership::ROLE_OWNER),
            $member, $delegatedMember, $role, $noRole,
            new BranchRoleAssignment($member, $branch, $noRole),
            new BranchRoleAssignment($delegatedMember, $branch, $role),
        ] as $entity) {
            $db->persist($entity);
        }
        $db->flush();

        return compact('tenant', 'foreign', 'branch', 'otherBranch', 'foreignBranch',
            'owner', 'viewer', 'delegate');
    }

    private function installFakeLedger(): void
    {
        // El controller HTTP es real. Solo el puerto de almacenamiento se sustituye por RAM descartable.
        class_exists(SocialSecurityApiService::class);
        $rows = [];
        $port = $this->createMock(SocialSecurityApiPort::class);
        $port->method('query')->willReturnCallback(
            static function (string $tenant, string $entity, ?string $ref) use (&$rows): array|null {
                $group = $tenant.'|'.$entity;
                return $ref === null ? array_values($rows[$group] ?? [])
                    : ($rows[$group][$ref] ?? null);
            },
        );
        $port->method('commit')->willReturnCallback(
            static function (array $data, string $command, string $actor, ?int $expected) use (&$rows): array {
                $group = $data['tenant_ref'].'|'.$data['entity_ref'];
                $ref = $data['affiliation_ref'];
                if ($command === 'register') {
                    if (isset($rows[$group][$ref])) {
                        throw new \DomainException('Duplicado.');
                    }
                    $rows[$group][$ref] = $data;
                } else {
                    $existing = $rows[$group][$ref] ?? null;
                    if ($existing === null || $existing['version'] !== $expected) {
                        throw new \DomainException('Versión inconsistente.');
                    }
                    $rows[$group][$ref] = array_replace($existing, [
                        'state' => $data['state'], 'version' => $existing['version'] + 1,
                    ]);
                }
                return $rows[$group][$ref];
            },
        );
        static::getContainer()->set(
            SocialSecurityApiService::class, new SocialSecurityApiService($port),
        );
    }

    private function base(Branch $branch): string
    {
        return '/api/v1/branches/'.$branch->id().'/social-security/affiliations';
    }

    private function get(KernelBrowser $client, string $url, int $expected): void
    {
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame($expected, $url);
    }

    private function csrf(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($token);
        self::assertNotSame('', $token);
        return $token;
    }

    /** @return array<string,mixed> */
    private function json(KernelBrowser $client): array
    {
        $result = json_decode((string) $client->getResponse()->getContent(),
            true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        return $result;
    }

    private function assertPrivateResponse(KernelBrowser $client): void
    {
        $headers = $client->getResponse()->headers;
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
    }
}
