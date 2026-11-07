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
use App\Tests\Support\BrowserCsrfToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** M02 S04–S08: real Symfony HTTP, fictional references only. */
final class ContractsLifecyclePreviewControllerTest extends WebTestCase
{
    use BrowserCsrfToken;

    /** @return array{Tenant,Branch,User} */
    private function fixture(EntityManagerInterface $em): array
    {
        $salt = bin2hex(random_bytes(5));
        $tenant = new Tenant('Contrato demo '.$salt, 'lifecycle-'.$salt);
        $company = new LegalEntity($tenant, 'Empresa inventada '.$salt.' SAS', null, true);
        $branch = new Branch($tenant, 'Sede inventada', 'principal-'.$salt, $company, true);
        $user = new User('m02-'.$salt.'@example.test', 'Revisor de ejemplo');
        foreach ([$tenant, $company, $branch, $user,
            new Membership($tenant, $user, Membership::ROLE_OWNER)] as $item) {
            $em->persist($item);
        }
        $em->flush();
        return [$tenant, $branch, $user];
    }

    /** @return array<string,mixed> */
    private static function payload(Tenant $tenant, Branch $branch): array
    {
        $scope = [
            'tenant_ref' => 'tenant-test-t'.substr(hash('sha256', $tenant->id()), 0, 15),
            'company_ref' => 'fixture:company/c'.substr(hash('sha256',
                $tenant->id().'|'.$branch->legalEntity()->id().'|'.$branch->id()), 0, 15),
        ];
        // Keep the fake records separate from the server-bound scope. Each
        // category gets the exact same scoped namespace before projection.
        $records = [
            'contracts' => [[
                'ref' => 'fixture:contract/basic',
                'worker_ref' => 'fixture:worker/demo',
                'start_on' => '2026-01-01',
                'end_on' => '2026-11-20',
            ]],
            'signature_evidence' => [[
                'ref' => 'fixture:signature-evidence/draft',
                'contract_ref' => 'fixture:contract/basic',
                'reviewer_ref' => 'fixture:reviewer/demo',
                'captured_on' => '2026-01-02',
            ]],
            'renewals' => [[
                'ref' => 'fixture:renewal/proposal',
                'contract_ref' => 'fixture:contract/basic',
                'reviewer_ref' => 'fixture:reviewer/demo',
                'proposed_end_on' => '2027-01-31',
            ]],
            'terminations' => [[
                'ref' => 'fixture:termination/draft',
                'contract_ref' => 'fixture:contract/basic',
                'reviewer_ref' => 'fixture:reviewer/demo',
                'effective_on' => '2026-11-20',
                'reason_key' => 'TERM_REVIEW',
            ]],
            'settlement_drafts' => [[
                'ref' => 'fixture:settlement-draft/estimate',
                'termination_ref' => 'fixture:termination/draft',
                'reviewer_ref' => 'fixture:reviewer/demo',
            ]],
        ];
        foreach ($records as &$entries) {
            foreach ($entries as &$entry) {
                $entry = $scope + $entry;
            }
            unset($entry);
        }
        unset($entries);

        return [...$scope, 'reference_date' => '2026-11-05', ...$records];
    }

    public function testFiveLifecycleSlicesAreReadOnlyAndNeverLegallyApproved(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$tenant, $branch, $owner] = $this->fixture($em);
        $url = '/api/v1/branches/'.$branch->id().'/contracts/lifecycle/preview';
        $payload = self::payload($tenant, $branch);
        $client->jsonRequest('POST', $url, $payload);
        self::assertResponseStatusCodeSame(401);
        $client->loginUser($owner);
        $client->jsonRequest('POST', $url, $payload);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/admin');
        $csrf = $this->csrfToken($client, 'branch_access');
        $client->jsonRequest('POST', $url, $payload, ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(200);
        $result = json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
        self::assertTrue($result['synthetic_only']);
        self::assertTrue($result['requires_human_review']);
        self::assertFalse($result['legal_value']);
        self::assertFalse($result['signature_validated']);
        self::assertFalse($result['notification_sent']);
        $preview = $result['lifecycle_preview'];
        self::assertTrue($preview['valid']);
        self::assertSame('REQUIERE_REVISION', $preview['state']);
        self::assertFalse($preview['signature_evidence'][0]['signature_validated']);
        self::assertFalse($preview['renewals'][0]['applied']);
        self::assertFalse($preview['renewals'][0]['notification_sent']);
        self::assertTrue($preview['contracts'][0]['renewal_attention_candidate']);
        self::assertFalse($preview['terminations'][0]['contract_closed']);
        self::assertNull($preview['settlement_drafts'][0]['amount_cop']);
        self::assertFalse($preview['settlement_drafts'][0]['approved']);
    }

    public function testTenantAndBranchIsolationAndRoleDenial(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$tenant, $branch, $owner] = $this->fixture($em);
        [, $foreignBranch] = $this->fixture($em);
        $second = new Branch($tenant, 'Otra sede', 'otra', $branch->legalEntity());
        $viewer = new User('m02-viewer-'.bin2hex(random_bytes(5)).'@example.test', 'Sin acceso');
        $membership = new Membership($tenant, $viewer, 'ADMIN');
        $role = new Role($tenant, 'Configuración sin inventario', ['settings.view']);
        foreach ([$second, $viewer, $membership, $role,
            new BranchRoleAssignment($membership, $branch, $role)] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $client->loginUser($owner);
        $client->request('GET', '/admin');
        $csrf = $this->csrfToken($client, 'branch_access');
        $payload = self::payload($tenant, $branch);
        foreach ([$second, $foreignBranch] as $notAllowed) {
            $client->jsonRequest('POST', '/api/v1/branches/'.$notAllowed->id().'/contracts/lifecycle/preview',
                $payload, ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame($notAllowed === $second ? 422 : 404);
        }
        $client->loginUser($viewer);
        $client->jsonRequest('POST', '/api/v1/branches/'.$branch->id().'/contracts/lifecycle/preview',
            $payload, ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPiiForgedScopeAndForbiddenStatesFailClosed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$tenant, $branch, $owner] = $this->fixture($em);
        $client->loginUser($owner);
        $client->request('GET', '/admin');
        $csrf = $this->csrfToken($client, 'branch_access');
        $url = '/api/v1/branches/'.$branch->id().'/contracts/lifecycle/preview';
        $valid = self::payload($tenant, $branch);
        $pii = $valid;
        $pii['contracts'][0]['email'] = 'trabajador@example.test';
        $foreign = $valid;
        $foreign['contracts'][0]['tenant_ref'] = 'tenant-test-foreign';
        $approved = $valid;
        $approved['terminations'][0]['reason_key'] = 'AUTO_APPROVED';
        $badScope = $valid;
        $badScope['company_ref'] = 'fixture:company/foreign';
        foreach ([$pii, $foreign, $approved, $badScope,
            $valid + ['employee_name' => 'Persona auténtica']] as $invalid) {
            $client->jsonRequest('POST', $url, $invalid, ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(422);
            self::assertStringNotContainsString('trabajador@example.test',
                (string) $client->getResponse()->getContent());
        }
    }
}
