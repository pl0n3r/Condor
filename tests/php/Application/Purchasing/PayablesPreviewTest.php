<?php

declare(strict_types=1);

namespace App\Tests\Application\Purchasing;

use App\Application\Purchasing\PayablesPreview;
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

/** M09/S08: fake COP obligations; guarded HTTP, no payments or SQL ledger writes. */
final class PayablesPreviewTest extends WebTestCase
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
            'mode' => 'preview_only',
            'obligations' => [
                [
                    'obligation_ref' => 'fixture:payable/zeta',
                    'supplier_ref' => 'fixture:supplier/demo',
                    'amount_cop' => '120000',
                    'currency' => 'COP',
                    'reconciliation_status' => 'unreconciled',
                    'payment_status' => 'unpaid',
                ] + $scope,
                [
                    'obligation_ref' => 'fixture:payable/alpha',
                    'supplier_ref' => 'fixture:supplier/other',
                    'amount_cop' => '34500',
                    'currency' => 'COP',
                    'reconciliation_status' => 'unreconciled',
                    'payment_status' => 'unpaid',
                ] + $scope,
            ],
        ];
    }

    public function testSyntheticCopProjectionIsDeterministicAndCannotPay(): void
    {
        $payload = self::fixturePayload();
        $expected = PayablesPreview::preview($payload);
        self::assertSame('154500', $expected['total_amount_cop']);
        self::assertSame('COP', $expected['currency']);
        self::assertSame('UNPAID_UNRECONCILED', $expected['status']);
        self::assertSame(2, $expected['obligation_count']);
        self::assertSame('fixture:payable/alpha', $expected['obligations'][0]['obligation_ref']);
        foreach (['can_pay', 'can_reconcile', 'can_settle', 'accounting_posted', 'persisted', 'notified'] as $field) {
            self::assertFalse($expected[$field]);
        }
        self::assertSame(0, $expected['payments_created']);
        $payload['obligations'] = array_reverse($payload['obligations']);
        self::assertSame($expected, PayablesPreview::preview($payload));
    }

    public function testInvalidScopePiiRealSuppliersPaymentsAndAmountsFailClosed(): void
    {
        $base = self::fixturePayload();
        $cases = [];
        foreach (['tenant_ref', 'legal_entity_ref', 'site_ref'] as $field) {
            $v = $base; $v[$field] .= '-foreign'; $cases[] = $v;
            $v = $base; $v['obligations'][0][$field] .= '-foreign'; $cases[] = $v;
        }
        foreach (['0', '001', '-1', '25.50', '1e4', '1000000000000', 120, 0.5, null] as $amount) {
            $v = $base; $v['obligations'][0]['amount_cop'] = $amount; $cases[] = $v;
        }
        foreach ([
            ['currency', 'USD'],
            ['reconciliation_status', 'reconciled'],
            ['payment_status', 'paid'],
            ['supplier_ref', 'supplier-real-123'],
            ['obligation_ref', 'https://pay.example.invalid'],
        ] as [$field, $value]) {
            $v = $base; $v['obligations'][0][$field] = $value; $cases[] = $v;
        }
        $v = $base; $v['mode'] = 'execute'; $cases[] = $v;
        $v = $base; $v['supplier_email'] = 'person@example.test'; $cases[] = $v;
        $v = $base; $v['create_payment'] = true; $cases[] = $v;
        $v = $base; $v['obligations'][0]['supplier_name'] = 'Persona real'; $cases[] = $v;
        $v = $base; $v['obligations'][0]['bank_account'] = '123456'; $cases[] = $v;
        $v = $base; $v['obligations'][] = $v['obligations'][0]; $cases[] = $v;
        $v = $base; $v['obligations'] = []; $cases[] = $v;
        foreach ($cases as $index => $invalid) {
            try {
                PayablesPreview::preview($invalid);
                self::fail('Debe rechazarse el fixture inválido '.$index);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testHttpRejectsAnonymousCsrfForeignBranchInvalidScopeAndNoPermission(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$tenant, $branch, $owner] = $this->fixtureUser($em);
        [, $foreign] = $this->fixtureUser($em);
        $viewer = new User('m09-pay-view-'.bin2hex(random_bytes(5)).'@example.test', 'Lector sintético');
        $membership = new Membership($tenant, $viewer, 'ADMIN');
        $role = new Role($tenant, 'Lectura sintética', ['inventory.view']);
        foreach ([$viewer, $membership, $role, new BranchRoleAssignment($membership, $branch, $role)] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $url = '/api/v1/branches/'.$branch->id().'/purchasing/payables/preview';
        $payload = self::fixturePayload();
        $client->jsonRequest('POST', $url, $payload);
        self::assertResponseStatusCodeSame(401);
        $client->loginUser($owner);
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $csrf = ['HTTP_X_CSRF_TOKEN' => $this->csrfToken($client, 'branch_access')];
        $client->jsonRequest('POST', $url, $payload);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $url, [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf['HTTP_X_CSRF_TOKEN'],
        ], '{"broken"');
        self::assertResponseStatusCodeSame(400);
        $client->jsonRequest('POST', $url, [...$payload, 'padding' => str_repeat('x', 70000)], $csrf);
        self::assertResponseStatusCodeSame(400);
        $client->jsonRequest('POST', $url, $payload, $csrf);
        self::assertResponseStatusCodeSame(200);
        $response = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($response['synthetic_only']);
        self::assertSame('154500', $response['payables_preview']['total_amount_cop']);
        self::assertFalse($response['payables_preview']['can_pay']);
        self::assertSame(0, $response['payables_preview']['payments_created']);

        $client->jsonRequest('POST', '/api/v1/branches/'.$foreign->id().'/purchasing/payables/preview', $payload, $csrf);
        self::assertResponseStatusCodeSame(404);
        foreach ([
            [...$payload, 'tenant_ref' => 'fixture:tenant/foreign'],
            [...$payload, 'legal_entity_ref' => 'fixture:legal-entity/foreign'],
            [...$payload, 'site_ref' => 'fixture:site/foreign'],
            [...$payload, 'supplier_name' => 'Persona'],
            [...$payload, 'obligations' => [[...$payload['obligations'][0], 'payment_status' => 'paid']]],
        ] as $invalid) {
            $client->jsonRequest('POST', $url, $invalid, $csrf);
            self::assertResponseStatusCodeSame(422);
        }
        $client->loginUser($viewer);
        $client->request('GET', '/admin');
        $viewerCsrf = ['HTTP_X_CSRF_TOKEN' => $this->csrfToken($client, 'branch_access')];
        $client->jsonRequest('POST', $url, $payload, $viewerCsrf);
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array{Tenant,Branch,User} */
    private function fixtureUser(EntityManagerInterface $em): array
    {
        $suffix = bin2hex(random_bytes(5));
        $em->persist($tenant = new Tenant('Cuentas sintéticas '.$suffix, 'm09-pay-'.$suffix));
        $em->persist($legal = new LegalEntity($tenant, 'SAS ficticia '.$suffix, null, true));
        $em->persist($branch = new Branch($tenant, 'Sede ficticia', 'm09-pay-'.$suffix, $legal, true));
        $em->persist($owner = new User('m09-pay-'.$suffix.'@example.test', 'Dueño ficticio'));
        $em->persist(new Membership($tenant, $owner, Membership::ROLE_OWNER));
        $em->flush();
        return [$tenant, $branch, $owner];
    }
}
