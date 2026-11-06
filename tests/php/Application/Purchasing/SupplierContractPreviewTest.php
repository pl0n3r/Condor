<?php

declare(strict_types=1);

namespace App\Tests\Application\Purchasing;

use App\Application\Purchasing\SupplierContractPreview;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Tests\Support\BrowserCsrfToken;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** M09 S07: synthetic metadata and authenticated HTTP boundary. */
final class SupplierContractPreviewTest extends WebTestCase
{
    use BrowserCsrfToken;

    /** @return array<string,mixed> */
    private static function fixturePayload(): array
    {
        $scope = [
            'tenant_ref' => 'fixture:tenant/current',
            'legal_entity_ref' => 'fixture:legal-entity/current',
            'site_ref' => 'fixture:site/current',
        ];
        return $scope + [
            'supplier_ref' => 'fixture:supplier/demo',
            'contract_ref' => 'fixture:contract/example',
            'mode' => 'preview_only',
            'metadata' => [
                'template_ref' => 'fixture:contract-template/standard',
                'kind' => 'services',
                'revision' => 1,
            ],
            'reviews' => array_map(
                static fn (string $stage): array => ['stage' => $stage, 'state' => 'pending'] + $scope,
                ['purchasing', 'legal', 'compliance'],
            ),
        ];
    }

    public function testSyntheticPreviewIsDeterministicAndNeverSigns(): void
    {
        $input = self::fixturePayload();
        $preview = SupplierContractPreview::preview($input);
        self::assertSame('REQUIRES_HUMAN_REVIEW', $preview['status']);
        self::assertSame('fixture:contract/example', $preview['contract_ref']);
        self::assertSame(['compliance' => 'pending', 'legal' => 'pending', 'purchasing' => 'pending'], $preview['reviews']);
        foreach (['can_approve', 'can_sign', 'can_send', 'can_order', 'juridically_binding', 'persisted', 'notified'] as $field) {
            self::assertFalse($preview[$field]);
        }
        $reverse = $input;
        $reverse['reviews'] = array_reverse($reverse['reviews']);
        self::assertSame($preview, SupplierContractPreview::preview($reverse));
        foreach ($input['reviews'] as &$review) {
            $review['state'] = 'reviewed';
        }
        unset($review);
        $allReviewed = SupplierContractPreview::preview($input);
        self::assertSame('REQUIRES_HUMAN_REVIEW', $allReviewed['status']);
        self::assertFalse($allReviewed['juridically_binding']);
        self::assertFalse($allReviewed['can_sign']);
    }

    public function testChangesRequestedRemainsNonBinding(): void
    {
        $fixture = self::fixturePayload();
        $fixture['reviews'][0]['state'] = 'changes_requested';
        $fixture['reviews'][1]['state'] = 'reviewed';
        $preview = SupplierContractPreview::preview($fixture);
        self::assertSame('changes_requested', $preview['reviews']['purchasing']);
        self::assertSame('reviewed', $preview['reviews']['legal']);
        self::assertSame('REQUIRES_HUMAN_REVIEW', $preview['status']);
        self::assertFalse($preview['can_approve']);
        self::assertFalse($preview['can_sign']);
        self::assertFalse($preview['juridically_binding']);
    }

    public function testScopeRealDataAndInvalidReviewInputsFailClosed(): void
    {
        $base = self::fixturePayload();
        $cases = [];
        $v = $base; $v['tenant_ref'] = 'fixture:tenant/foreign'; $cases[] = $v;
        $v = $base; $v['reviews'][0]['tenant_ref'] = 'fixture:tenant/foreign'; $cases[] = $v;
        $v = $base; $v['reviews'][0]['legal_entity_ref'] = 'fixture:legal-entity/foreign'; $cases[] = $v;
        $v = $base; $v['reviews'][0]['site_ref'] = 'fixture:site/foreign'; $cases[] = $v;
        $v = $base; $v['supplier_ref'] = 'real-supplier'; $cases[] = $v;
        $v = $base; $v['contract_ref'] = 'real-contract'; $cases[] = $v;
        $v = $base; $v['mode'] = 'sign'; $cases[] = $v;
        $v = $base; $v['reviewer_email'] = 'person@example.test'; $cases[] = $v;
        $v = $base; $v['metadata']['template_ref'] = 'https://example.test/contract'; $cases[] = $v;
        $v = $base; $v['metadata']['kind'] = 'employment'; $cases[] = $v;
        foreach ([0, 100, '2', null, 1.5] as $bad) {
            $v = $base; $v['metadata']['revision'] = $bad; $cases[] = $v;
        }
        $v = $base; $v['metadata']['signature'] = true; $cases[] = $v;
        $v = $base; $v['reviews'] = []; $cases[] = $v;
        $v = $base; $v['reviews'][] = $v['reviews'][0]; $cases[] = $v;
        $v = $base; $v['reviews'][1]['stage'] = 'purchasing'; $cases[] = $v;
        $v = $base; $v['reviews'][1]['stage'] = 'finance'; $cases[] = $v;
        $v = $base; $v['reviews'][1]['state'] = 'approved'; $cases[] = $v;
        $v = $base; $v['reviews'][1]['reviewer_name'] = 'Real Name'; $cases[] = $v;
        foreach ($cases as $index => $case) {
            try {
                SupplierContractPreview::preview($case);
            } catch (InvalidArgumentException) {
                continue;
            }
            self::fail('Fixture inválido '.$index.' debió rechazarse.');
        }
    }

    public function testHttpRequiresAuthenticationCsrfTenantAndPermission(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$tenant, $branch, $owner, $viewer, $foreign] = $this->contractActors($em);
        $path = '/api/v1/branches/'.$branch->id().'/purchasing/suppliers/contracts/preview';
        $payload = self::fixturePayload();
        // A single transport helper keeps each negative assertion focused on policy.
        $send = static function (array $value, array $headers = []) use ($client, $path): void {
            $client->jsonRequest('POST', $path, $value, $headers);
        };
        $send($payload);
        self::assertResponseStatusCodeSame(401);
        $client->loginUser($owner);
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $csrf = ['HTTP_X_CSRF_TOKEN' => $this->csrfToken($client, 'branch_access')];
        $send($payload);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $path, [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf['HTTP_X_CSRF_TOKEN'],
        ], '{"broken"');
        self::assertResponseStatusCodeSame(400);
        $send([...$payload, 'padding' => str_repeat('x', 70000)], $csrf);
        self::assertResponseStatusCodeSame(400);
        $send($payload, $csrf);
        self::assertResponseStatusCodeSame(200);
        $output = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($output['synthetic_only']);
        self::assertSame('REQUIRES_HUMAN_REVIEW', $output['supplier_contract_preview']['status']);
        self::assertFalse($output['supplier_contract_preview']['juridically_binding']);
        self::assertFalse($output['supplier_contract_preview']['can_sign']);
        self::assertFalse($output['supplier_contract_preview']['persisted']);

        $client->jsonRequest('POST', '/api/v1/branches/'.$foreign->id().'/purchasing/suppliers/contracts/preview', $payload, $csrf);
        self::assertResponseStatusCodeSame(404);
        foreach ([
            [...$payload, 'supplier_id' => 'person@example.test'],
            [...$payload, 'tenant_ref' => 'fixture:tenant/foreign'],
            [...$payload, 'legal_entity_ref' => 'fixture:legal-entity/foreign'],
            [...$payload, 'site_ref' => 'fixture:site/foreign'],
            [...$payload, 'reviews' => [[...$payload['reviews'][0], 'site_ref' => 'fixture:site/foreign'], ...array_slice($payload['reviews'], 1)]],
            [...$payload, 'metadata' => [...$payload['metadata'], 'signature' => true]],
            [...$payload, 'reviews' => [[...$payload['reviews'][0], 'state' => 'approved'], ...array_slice($payload['reviews'], 1)]],
        ] as $invalid) {
            $send($invalid, $csrf);
            self::assertResponseStatusCodeSame(422);
        }
        $client->loginUser($viewer);
        $client->request('GET', '/admin');
        $viewerCsrf = ['HTTP_X_CSRF_TOKEN' => $this->csrfToken($client, 'branch_access')];
        $send($payload, $viewerCsrf);
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array{Tenant,Branch,User,User,Branch} */
    private function contractActors(EntityManagerInterface $em): array
    {
        [$tenant, $branch, $owner] = $this->fixtureUser($em);
        [, $foreign] = $this->fixtureUser($em);
        $viewer = new User('m09-contract-view-'.bin2hex(random_bytes(5)).'@example.test', 'Lector ficticio');
        $membership = new Membership($tenant, $viewer, 'ADMIN');
        $role = new Role($tenant, 'Lector ficticio', ['inventory.view']);
        $assignedRole = new BranchRoleAssignment($membership, $branch, $role);
        $em->persist($viewer);
        $em->persist($membership);
        $em->persist($role);
        $em->persist($assignedRole);
        $em->flush();

        return [$tenant, $branch, $owner, $viewer, $foreign];
    }

    /** @return array{Tenant,Branch,User} */
    private function fixtureUser(EntityManagerInterface $em): array
    {
        $token = bin2hex(random_bytes(5));
        $tenant = new Tenant(sprintf('Contrato de prueba %s', $token), 'm09-contract-'.$token);
        $legalEntity = new LegalEntity($tenant, 'SAS de prueba '.$token, null, true);
        $site = new Branch($tenant, 'Sede para contratos', 'm09-contract-'.$token, $legalEntity, true);
        $owner = new User('m09-contract-'.$token.'@example.test', 'Dueño ficticio');
        $ownerMembership = new Membership($tenant, $owner, Membership::ROLE_OWNER);

        $em->persist($tenant);
        $em->persist($legalEntity);
        $em->persist($site);
        $em->persist($owner);
        $em->persist($ownerMembership);
        $em->flush();

        return [$tenant, $site, $owner];
    }
}
