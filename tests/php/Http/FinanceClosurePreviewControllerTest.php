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

/** #1568: contrato HTTP real, exclusivamente fixtures y sin cierre efectivo. */
final class FinanceClosurePreviewControllerTest extends WebTestCase
{
    use BrowserCsrfToken;

    /** @return array{Tenant,Branch,User} */
    private function tenantFixture(EntityManagerInterface $em): array
    {
        $salt = bin2hex(random_bytes(5));
        $tenant = new Tenant('Finanzas '.$salt, 'fin-'.$salt);
        $legal = new LegalEntity($tenant, 'Finance '.$salt.' SAS', null, true);
        $branch = new Branch($tenant, 'Sede demo', 'sede-'.$salt, $legal, true);
        $owner = new User('finance-'.$salt.'@example.test', 'Operador demo');
        foreach ([$tenant, $legal, $branch, $owner,
            new Membership($tenant, $owner, Membership::ROLE_OWNER)] as $item) {
            $em->persist($item);
        }
        $em->flush();
        return [$tenant, $branch, $owner];
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        $raw = file_get_contents(__DIR__.'/../Application/Finance/fixtures/closure-s06.json');
        self::assertIsString($raw);
        $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($value);
        return $value;
    }

    public function testOwnerPreviewIsReadOnlyAndRequiresCsrf(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [, $branch, $owner] = $this->tenantFixture($em);
        $url = '/api/v1/branches/'.$branch->id().'/finance/closures/preview';
        $client->jsonRequest('POST', $url, $this->payload());
        self::assertResponseStatusCodeSame(401); // Authentication precedes CSRF.
        $client->loginUser($owner);
        $client->jsonRequest('POST', $url, $this->payload());
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $csrf = $this->csrfToken($client, 'branch_access');
        $client->jsonRequest('POST', $url, $this->payload(), ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
        self::assertTrue($json['synthetic_only']);
        $preview = $json['closure_preview'];
        self::assertTrue($preview['synthetic_only']);
        self::assertSame('REQUIERE_REVISION', $preview['state']);
        self::assertFalse($preview['can_close']);
        self::assertFalse($preview['can_approve']);
        self::assertSame(1, $preview['matched_candidate_count']);
        self::assertFalse($preview['periods'][0]['can_close']);
    }

    public function testCrossTenantAndUnprivilegedRequestsFailClosed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$tenant, $branch, $owner] = $this->tenantFixture($em);
        [, $foreign] = $this->tenantFixture($em);
        $viewer = new User('fin-viewer-'.bin2hex(random_bytes(5)).'@example.test', 'Solo lectura');
        $membership = new Membership($tenant, $viewer, 'ADMIN');
        $role = new Role($tenant, 'Lectura finanzas', ['settings.view']);
        foreach ([$viewer, $membership, $role,
            new BranchRoleAssignment($membership, $branch, $role)] as $item) {
            $em->persist($item);
        }
        $em->flush();
        $client->loginUser($owner);
        $client->request('GET', '/admin');
        $csrf = $this->csrfToken($client, 'branch_access');
        $client->jsonRequest('POST',
            '/api/v1/branches/'.$foreign->id().'/finance/closures/preview',
            $this->payload(), ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);
        $client->loginUser($viewer);
        $client->jsonRequest('POST',
            '/api/v1/branches/'.$branch->id().'/finance/closures/preview',
            $this->payload(), ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testRealDataAndUnexpectedFieldsNeverBecomeClosable(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [, $branch, $owner] = $this->tenantFixture($em);
        $client->loginUser($owner);
        $client->request('GET', '/admin');
        $csrf = $this->csrfToken($client, 'branch_access');
        $url = '/api/v1/branches/'.$branch->id().'/finance/closures/preview';
        $client->jsonRequest('POST', $url, [...$this->payload(), 'bank_account' => '123456789'],
            ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(422);
        $foreign = $this->payload();
        $foreign['tenant_ref'] = 'fixture:tenant/foreign';
        $client->jsonRequest('POST', $url, $foreign, ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame([], $decoded['closure_preview']['periods']);
        self::assertFalse($decoded['closure_preview']['can_close']);
        self::assertStringNotContainsString('fixture:tenant/foreign', $body);
    }
}
