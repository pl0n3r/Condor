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
 * #1863: real Symfony HTTP, disposable fixtures only. S02-S06 previews never
 * query a real person, persist, transmit PILA, pay, or notify an external system.
 */
final class GestM05RemainingSlicesHttpJourneyTest extends WebTestCase
{
    /** @var array<string,string|false> */
    private array $originalEnv = [];

    /** @return list<string> */
    private static function slices(): array
    {
        return ['novelties', 'contributions', 'supports', 'alerts', 'pila'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'SOCIAL_SECURITY_REAL_DATA_ENABLED' => 'false',
            'CONDOR_SOCIAL_SECURITY_SYNTHETIC_API' => '0',
            'CONDOR_PRODUCTION_STAGE' => 'construccion',
        ] as $key => $value) {
            $this->originalEnv[$key] = getenv($key);
            putenv($key.'='.$value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
        parent::tearDown();
    }

    public function testFivePreviewRoutesAreRealHttpButSyntheticOnly(): void
    {
        $http = static::createClient();
        $http->disableReboot();
        $world = $this->world();
        $http->loginUser($world['owner']);
        $csrf = $this->csrf($http);
        $companyId = $world['company']->id();

        foreach (self::slices() as $slice) {
            $this->post($http, $this->url($world['branch'], $slice),
                $this->fixture($slice, $companyId), $csrf, 200);
            $body = $this->json($http);
            self::assertSame($slice, $body['slice']);
            foreach ([
                'synthetic' => true, 'persisted' => false, 'legal_value' => false,
                'can_send' => false, 'notifications_sent' => false,
                'payments_made' => false,
            ] as $key => $value) {
                self::assertSame($value, $body[$key] ?? null, $slice.' '.$key);
            }
            self::assertSame('REQUIERE_REVISION', $body['state']);
            $this->assertPrivate($http);
            $raw = (string) $http->getResponse()->getContent();
            self::assertStringNotContainsString($world['tenant']->id(), $raw);
            self::assertStringNotContainsString($companyId, $raw);
            self::assertStringNotContainsString('@example.test', $raw);
            self::assertStringNotContainsString('document_number', $raw);
            self::assertStringNotContainsString('diagnosis', $raw);
            if ($slice === 'novelties') {
                self::assertSame(['fixture:novelty/alpha'], $body['rows'][0]['novelties']);
            } elseif ($slice === 'contributions') {
                self::assertSame(500, $body['rows'][0]['health_units']);
                self::assertSame(800, $body['rows'][0]['pension_units']);
            } elseif ($slice === 'supports') {
                self::assertSame('fixture:support/alpha', $body['supports'][0]['support_ref']);
            } elseif ($slice === 'alerts') {
                self::assertSame(true, $body['alerts'][0]['attention_candidate']);
            } else {
                self::assertSame('BORRADOR_NO_ENVIABLE', $body['pila_example']['state']);
                self::assertSame(false, $body['pila_example']['can_send']);
                self::assertSame(1, $body['pila_example']['support_count']);
            }

            // Preview is intentionally POST-only. No public list, write, or state transition.
            $http->request('GET', $this->url($world['branch'], $slice));
            self::assertResponseStatusCodeSame(405);
            $http->jsonRequest('PATCH', $this->url($world['branch'], $slice),
                ['state' => 'ENVIADO'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(405);
        }
        // Original non-synthetic S02-S06 routes stay absent: no migrations or real API.
        $http->request('GET', '/api/v1/branches/'.$world['branch']->id().'/social-security/pila');
        self::assertResponseStatusCodeSame(404);
    }

    public function testCompanyTenantPermissionAndOwnerAreEnforcedOnEverySlice(): void
    {
        $http = static::createClient();
        $http->disableReboot();
        $world = $this->world();
        $companyId = $world['company']->id();
        $http->loginUser($world['owner']);
        $csrf = $this->csrf($http);

        foreach (self::slices() as $slice) {
            $payload = $this->fixture($slice, $companyId);
            $url = $this->url($world['branch'], $slice);
            $this->post($http, $this->url($world['otherBranch'], $slice), $payload, $csrf, 404);
            $this->post($http, $this->url($world['foreignBranch'], $slice), $payload, $csrf, 404);
            $this->post($http, $url,
                array_replace($payload, ['company_id' => $world['otherCompany']->id()]), $csrf, 404);

            $http->loginUser($world['viewer']); // No permission
            $this->post($http, $url, $payload, $csrf, 403);
            $http->loginUser($world['delegate']); // users.view, but not owner
            $this->post($http, $url, $payload, $csrf, 403);
            $http->loginUser($world['owner']);
        }
        $http->restart();
        $http->jsonRequest('POST', $this->url($world['branch'], 'pila'),
            $this->fixture('pila', $companyId), ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertContains($http->getResponse()->getStatusCode(), [401, 403]);
    }

    public function testCsrfMalformedPayloadAndNonFixtureSourcesFailClosed(): void
    {
        $http = static::createClient();
        $http->disableReboot();
        $world = $this->world();
        $http->loginUser($world['owner']);
        $url = $this->url($world['branch'], 'novelties');
        $valid = $this->fixture('novelties', $world['company']->id());

        $http->jsonRequest('POST', $url, $valid);
        self::assertResponseStatusCodeSame(403);
        $http->jsonRequest('POST', $url, $valid, ['HTTP_X_CSRF_TOKEN' => 'tampered']);
        self::assertResponseStatusCodeSame(403);
        $csrf = $this->csrf($http);

        foreach ([
            $valid + ['tenant_ref' => 'fixture:tenant/foreign'],
            $valid + ['document_number' => '123456789'],
            array_replace($valid, ['policy' => ['version' => 'fixture:policy/demo',
                'health_bp' => 15000, 'pension_bp' => 800]]),
            array_replace($valid, ['affiliations' => [[
                'employee_ref' => 'cedula:123456',
                'affiliation_ref' => 'fixture:affiliation/alpha', 'base_units' => 10000,
            ]]]),
            array_replace($valid, ['novelties' => [[
                'employee_ref' => 'fixture:employee/alpha',
                'novelty_ref' => 'fixture:novelty/alpha',
                'tenant_ref' => 'fixture:tenant/foreign',
            ]]]),
            array_replace($valid, ['affiliations' => array_fill(0, 101,
                $valid['affiliations'][0])]),
        ] as $bad) {
            $this->post($http, $url, $bad, $csrf, 422);
        }
        $http->request('POST', $url, server: ['HTTP_X_CSRF_TOKEN' => $csrf],
            content: '{json');
        self::assertResponseStatusCodeSame(400);
        // No HTTP upload path or unexpected JSON fields for documents.
        $this->post($http, $this->url($world['branch'], 'supports'),
            $this->fixture('supports', $world['company']->id())
                + ['file_upload' => 'private.pdf'], $csrf, 422);
        $this->post($http, $this->url($world['branch'], 'alerts'),
            array_replace($this->fixture('alerts', $world['company']->id()), [
                'alerts' => [[
                    'employee_ref' => 'fixture:employee/alpha',
                    'alert_ref' => 'fixture:alert/alpha', 'due_on' => '2026-02-30',
                ]],
            ]), $csrf, 422);
        $this->post($http, $this->url($world['branch'], 'pila'),
            array_replace($this->fixture('pila', $world['company']->id()), [
                'supports' => [[
                    'employee_ref' => 'fixture:employee/alpha',
                    'support_ref' => 'fixture:support/alpha',
                ], [
                    'employee_ref' => 'fixture:employee/alpha',
                    'support_ref' => 'fixture:support/alpha',
                ]],
            ]), $csrf, 422);
    }

    public function testFeatureFlagOnDisablesAllSyntheticEndpoints(): void
    {
        $http = static::createClient();
        $http->disableReboot();
        $world = $this->world();
        $http->loginUser($world['owner']);
        $csrf = $this->csrf($http);
        putenv('SOCIAL_SECURITY_REAL_DATA_ENABLED=true');
        foreach (self::slices() as $slice) {
            $this->post($http, $this->url($world['branch'], $slice),
                $this->fixture($slice, $world['company']->id()), $csrf, 404);
            self::assertSame('feature_unavailable', $this->json($http)['error']);
            $this->assertPrivate($http);
        }
    }

    /** @return array<string,mixed> */
    private function world(): array
    {
        $db = static::getContainer()->get(EntityManagerInterface::class);
        $id = substr(bin2hex(random_bytes(6)), 0, 5).'a'.substr(bin2hex(random_bytes(6)), 0, 5);
        $tenant = new Tenant('M05 preview synthetic', 'previewa-'.$id);
        $foreign = new Tenant('M05 preview foreign', 'previewb-'.$id);
        $company = new LegalEntity($tenant, 'Empresa sintética A', null, true);
        $otherCompany = new LegalEntity($tenant, 'Empresa sintética B', null);
        $foreignCompany = new LegalEntity($foreign, 'Empresa sintética X', null, true);
        $branch = new Branch($tenant, 'Sede 1', 'previewa', $company, true);
        $otherBranch = new Branch($tenant, 'Sede 2', 'previewb', $otherCompany);
        $foreignBranch = new Branch($foreign, 'Sede 3', 'previewc', $foreignCompany);
        $owner = new User('preview-owner-'.$id.'@example.test', 'Owner fixture');
        $viewer = new User('preview-viewer-'.$id.'@example.test', 'No permission fixture');
        $delegate = new User('preview-delegate-'.$id.'@example.test', 'Non-owner fixture');
        $viewerMember = new Membership($tenant, $viewer, 'ADMIN');
        $delegateMember = new Membership($tenant, $delegate, 'ADMIN');
        $viewerRole = new Role($tenant, 'Sin lectura M05', ['catalog.view']);
        $delegateRole = new Role($tenant, 'Solo lectura M05', ['users.view']);
        foreach ([
            $tenant, $foreign, $company, $otherCompany, $foreignCompany,
            $branch, $otherBranch, $foreignBranch,
            $owner, $viewer, $delegate,
            new Membership($tenant, $owner, Membership::ROLE_OWNER),
            $viewerMember, $delegateMember, $viewerRole, $delegateRole,
            new BranchRoleAssignment($viewerMember, $branch, $viewerRole),
            new BranchRoleAssignment($delegateMember, $branch, $delegateRole),
        ] as $entity) {
            $db->persist($entity);
        }
        $db->flush();
        return compact('tenant', 'company', 'otherCompany', 'branch',
            'otherBranch', 'foreignBranch', 'owner', 'viewer', 'delegate');
    }

    /** @return array<string,mixed> */
    private function fixture(string $slice, string $companyId): array
    {
        if (in_array($slice, ['novelties', 'contributions'], true)) {
            return [
                'company_id' => $companyId,
                'policy' => ['version' => 'fixture:policy/demo', 'health_bp' => 500, 'pension_bp' => 800],
                'affiliations' => [[
                    'employee_ref' => 'fixture:employee/alpha',
                    'affiliation_ref' => 'fixture:affiliation/alpha',
                    'base_units' => 10000,
                ]],
                'novelties' => [[
                    'employee_ref' => 'fixture:employee/alpha',
                    'novelty_ref' => 'fixture:novelty/alpha',
                ]],
            ];
        }
        return [
            'company_id' => $companyId,
            'reference_date' => '2026-11-07',
            'supports' => [[
                'employee_ref' => 'fixture:employee/alpha',
                'support_ref' => 'fixture:support/alpha',
            ]],
            'alerts' => [[
                'employee_ref' => 'fixture:employee/alpha',
                'alert_ref' => 'fixture:alert/alpha',
                'due_on' => '2026-11-01',
            ]],
        ];
    }

    private function url(Branch $branch, string $slice): string
    {
        return '/api/v1/branches/'.$branch->id().'/social-security/synthetic/'.$slice.'/preview';
    }

    /** @param array<string,mixed> $body */
    private function post(KernelBrowser $http, string $url, array $body, string $csrf, int $code): void
    {
        $http->jsonRequest('POST', $url, $body, ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame($code, $url);
    }

    private function csrf(KernelBrowser $http): string
    {
        $crawler = $http->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($token);
        self::assertNotSame('', $token);
        return $token;
    }

    /** @return array<string,mixed> */
    private function json(KernelBrowser $http): array
    {
        $data = json_decode((string) $http->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    private function assertPrivate(KernelBrowser $http): void
    {
        $headers = $http->getResponse()->headers;
        self::assertStringContainsString('private', (string) $headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
    }
}
