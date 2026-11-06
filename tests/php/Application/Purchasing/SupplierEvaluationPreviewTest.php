<?php

declare(strict_types=1);

namespace App\Tests\Application\Purchasing;

use App\Application\Purchasing\SupplierEvaluationPreview;
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

/** M09 S06: pure validation + real authenticated Symfony boundary. */
final class SupplierEvaluationPreviewTest extends WebTestCase
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
            'mode' => 'preview_only',
            'criteria' => array_map(static fn (string $metric): array =>
                ['metric' => $metric, 'score' => 4] + $scope,
                ['quality', 'delivery', 'compliance']),
        ];
    }

    public function testDeterministicSyntheticPreviewNeverAwardsOrPersists(): void
    {
        $input = self::fixturePayload();
        $preview = SupplierEvaluationPreview::preview($input);
        self::assertSame('REQUIRES_HUMAN_REVIEW', $preview['status']);
        self::assertSame('fixture:supplier/demo', $preview['supplier_ref']);
        self::assertSame(80, $preview['score_percent']);
        self::assertSame(['compliance' => 4, 'delivery' => 4, 'quality' => 4], $preview['scores']);
        foreach (['can_award', 'can_approve', 'can_order', 'can_pay', 'persisted', 'notified'] as $key) {
            self::assertFalse($preview[$key]);
        }
        $reverse = $input;
        $reverse['criteria'] = array_reverse($reverse['criteria']);
        self::assertSame($preview, SupplierEvaluationPreview::preview($reverse));
    }

    public function testInvalidCrossScopeRealDataAndBoundaryValuesFailClosed(): void
    {
        $base = self::fixturePayload();
        $cases = [];
        $v = $base; $v['tenant_ref'] = 'fixture:tenant/foreign'; $cases[] = $v;
        $v = $base; $v['criteria'][0]['legal_entity_ref'] = 'fixture:legal-entity/foreign'; $cases[] = $v;
        $v = $base; $v['criteria'][0]['site_ref'] = 'fixture:site/foreign'; $cases[] = $v;
        $v = $base; $v['supplier_ref'] = 'real-supplier-id'; $cases[] = $v;
        $v = $base; $v['supplier_email'] = 'person@example.test'; $cases[] = $v;
        $v = $base; $v['mode'] = 'award'; $cases[] = $v;
        $v = $base; $v['criteria'] = []; $cases[] = $v;
        $v = $base; $v['criteria'][] = $v['criteria'][0]; $cases[] = $v;
        $v = $base; $v['criteria'][1]['metric'] = 'quality'; $cases[] = $v;
        $v = $base; $v['criteria'][1]['metric'] = 'price'; $cases[] = $v;
        foreach ([-1, 6, '4', null, 2.5] as $bad) {
            $v = $base; $v['criteria'][0]['score'] = $bad; $cases[] = $v;
        }
        $v = $base; $v['criteria'][0]['contact'] = 'someone@example.test'; $cases[] = $v;
        foreach ($cases as $case) {
            try {
                SupplierEvaluationPreview::preview($case);
                self::fail('La evaluación con datos reales o ámbito inválido debió rechazarse.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $zeros = $base;
        foreach ($zeros['criteria'] as &$criterion) { $criterion['score'] = 0; }
        unset($criterion);
        self::assertSame(0, SupplierEvaluationPreview::preview($zeros)['score_percent']);
    }

    public function testHttpEnforcesAuthenticationCsrfScopeAndNoAutomaticAward(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$tenant, $branch, $owner] = $this->fixtureUser($em);
        [, $foreign] = $this->fixtureUser($em);
        $path = '/api/v1/branches/'.$branch->id().'/purchasing/suppliers/evaluations/preview';
        $payload = self::fixturePayload();

        $client->jsonRequest('POST', $path, $payload);
        self::assertResponseStatusCodeSame(403);
        $client->loginUser($owner);
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $csrf = ['HTTP_X_CSRF_TOKEN' => $this->csrfToken($client, 'branch_access')];
        $client->jsonRequest('POST', $path, $payload);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $path, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf['HTTP_X_CSRF_TOKEN'],
        ], '{"broken"');
        self::assertResponseStatusCodeSame(400);
        $client->jsonRequest('POST', $path, [
            ...$payload, 'padding' => str_repeat('x', 70000),
        ], $csrf);
        self::assertResponseStatusCodeSame(400);
        $client->jsonRequest('POST', $path, $payload, $csrf);
        self::assertResponseStatusCodeSame(200);
        $response = json_decode((string)$client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($response['synthetic_only']);
        self::assertFalse($response['supplier_evaluation_preview']['can_award']);
        self::assertFalse($response['supplier_evaluation_preview']['persisted']);
        $client->jsonRequest('POST', '/api/v1/branches/'.$foreign->id().'/purchasing/suppliers/evaluations/preview', $payload, $csrf);
        self::assertResponseStatusCodeSame(404);
        foreach ([
            [...$payload, 'supplier_id' => 'someone@example.test'],
            [...$payload, 'legal_entity_ref' => 'fixture:legal-entity/foreign'],
            [...$payload, 'site_ref' => 'fixture:site/foreign'],
            [...$payload, 'tenant_ref' => 'fixture:tenant/foreign'],
        ] as $invalid) {
            $client->jsonRequest('POST', $path, $invalid, $csrf);
            self::assertResponseStatusCodeSame(422);
        }
        $viewer = new User('m09-view-'.bin2hex(random_bytes(5)).'@example.test', 'Lector ficticio');
        $membership = new Membership($tenant, $viewer, 'ADMIN');
        $role = new Role($tenant, 'Lectura inventario ficticio', ['inventory.view']);
        foreach ([$viewer, $membership, $role, new BranchRoleAssignment($membership, $branch, $role)] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $client->loginUser($viewer);
        $client->request('GET', '/admin');
        $csrfViewer = ['HTTP_X_CSRF_TOKEN' => $this->csrfToken($client, 'branch_access')];
        $client->jsonRequest('POST', $path, $payload, $csrfViewer);
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array{Tenant,Branch,User} */
    private function fixtureUser(EntityManagerInterface $em): array
    {
        $suffix = bin2hex(random_bytes(5));
        $tenant = new Tenant('Evaluación sintética '.$suffix, 'm09-eval-'.$suffix);
        $legal = new LegalEntity($tenant, 'SAS ficticia '.$suffix, null, true);
        $branch = new Branch($tenant, 'Sede ficticia', 'm09-eval-'.$suffix, $legal, true);
        $owner = new User('m09-eval-'.$suffix.'@example.test', 'Dueño ficticio');
        foreach ([$tenant, $legal, $branch, $owner,
            new Membership($tenant, $owner, Membership::ROLE_OWNER)] as $row) {
            $em->persist($row);
        }
        $em->flush();
        return [$tenant, $branch, $owner];
    }
}
