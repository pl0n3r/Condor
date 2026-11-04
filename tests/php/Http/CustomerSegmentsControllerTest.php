<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Commerce\Entity\CommercialCategory;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Orders\Entity\Order;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CustomerSegmentsControllerTest extends WebTestCase
{
    public function testSegmentsAndHistoryAreTenantAndBranchScoped(): void
    {
        $client = static::createClient();
        $em = $this->em();
        [$tenant, $branchA, $branchB, $sourceA, $sourceB, $owner] =
            $this->fixture($em);
        $category = new CommercialCategory(
            $tenant, 'Mayorista', 'mayorista-'.bin2hex(random_bytes(4)),
        );
        $recent = new Customer($tenant, 'Compra reciente', commercialCategory: $category);
        $otherBranch = new Customer($tenant, 'Compra en otra sede');
        $never = new Customer($tenant, 'Sin compras');
        foreach ([$category, $recent, $otherBranch, $never] as $item) {
            $em->persist($item);
        }
        $orderA = new Order(
            $tenant, $sourceA, null, $recent, $owner->id(),
            'crm-a-'.bin2hex(random_bytes(6)), 'COP', 2500,
        );
        $orderB = new Order(
            $tenant, $sourceB, null, $otherBranch, $owner->id(),
            'crm-b-'.bin2hex(random_bytes(6)), 'COP', 7000,
        );
        $em->persist($orderA);
        $em->persist($orderB);
        $em->flush();

        $client->loginUser($owner);
        $csrf = $this->csrf($client);
        $url = '/api/v1/branches/'.$branchA->id().'/customers/segments';
        $client->jsonRequest('POST', $url, [
            'segment' => 'with_recent_purchases_90_days',
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        self::assertSame([$recent->id()], $this->ids($client));

        $client->jsonRequest('POST', $url, [
            'segment' => 'without_purchases',
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing(
            [$otherBranch->id(), $never->id()], $this->ids($client),
        );

        $client->jsonRequest('POST', $url, [
            'segment' => 'by_category', 'category_id' => $category->id(),
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        self::assertSame([$recent->id()], $this->ids($client));

        $history = '/api/v1/branches/'.$branchA->id().'/customers/history';
        $client->jsonRequest('POST', $history, [
            'customer_id' => $recent->id(), 'page' => 1,
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        self::assertSame($recent->id(), $data['customer_id']);
        self::assertSame([$orderA->id()], array_column($data['orders'], 'id'));
        self::assertSame(1, $data['summary']['order_count']);
        self::assertSame('2500', $data['summary']['total_purchased_by_currency_minor']['COP']);
        self::assertFalse($data['pagination']['has_next']);

        $client->jsonRequest('POST', $history, [
            'customer_id' => $otherBranch->id(),
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json($client)['orders']);
        self::assertSame(0, $this->json($client)['summary']['order_count']);

        $client->jsonRequest('POST',
            '/api/v1/branches/'.$branchB->id().'/customers/history',
            ['customer_id' => $otherBranch->id()],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseIsSuccessful();
        self::assertSame([$orderB->id()], array_column($this->json($client)['orders'], 'id'));
    }

    public function testPermissionCsrfAndCrossTenantIdsFailClosed(): void
    {
        $client = static::createClient();
        $em = $this->em();
        [$tenant, $branch, $branchB, , , $owner] = $this->fixture($em);
        $otherTenant = new Tenant(
            'Ajena', 'ajena-'.bin2hex(random_bytes(4)),
        );
        $otherCategory = new CommercialCategory($otherTenant, 'Ajena', 'ajena');
        $otherCustomer = new Customer($otherTenant, 'Persona ajena');
        $worker = new User('crm-staff-'.bin2hex(random_bytes(5)).'@example.test', 'Vendedor');
        $member = new Membership($tenant, $worker, 'STAFF');
        $role = new Role($tenant, 'Solo clientes', ['customers.view']);
        $assignment = new BranchRoleAssignment($member, $branch, $role);
        foreach ([$otherTenant, $otherCategory, $otherCustomer, $worker, $member, $role, $assignment] as $item) {
            $em->persist($item);
        }
        $em->flush();

        $search = '/api/v1/branches/'.$branch->id().'/customers/segments';
        $history = '/api/v1/branches/'.$branch->id().'/customers/history';
        $client->loginUser($owner);
        $csrf = $this->csrf($client);
        $client->jsonRequest('POST', $search, [
            'segment' => 'by_category', 'category_id' => $otherCategory->id(),
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest('POST', $history, [
            'customer_id' => $otherCustomer->id(),
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest('POST', $search, [
            'term' => 'sensitive@example.test',
        ]);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', $search.'?term=sensitive@example.test');
        self::assertResponseStatusCodeSame(405);
        $client->request('GET', $history.'?customer_id='.$otherCustomer->id());
        self::assertResponseStatusCodeSame(405);

        $client->loginUser($worker);
        $csrf = $this->csrf($client);
        $client->jsonRequest('POST', $search, ['segment' => 'all'],
            ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseIsSuccessful();
        $client->jsonRequest('POST', $history, [
            'customer_id' => $otherCustomer->id(),
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST',
            '/api/v1/branches/'.$branchB->id().'/customers/segments',
            ['segment' => 'all'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(403);
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @return list<string> */
    private function ids(KernelBrowser $client): array
    {
        return array_column($this->json($client)['customers'], 'id');
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
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

    /** @return array{Tenant, Branch, Branch, InventorySource, InventorySource, User} */
    private function fixture(EntityManagerInterface $em): array
    {
        $suffix = bin2hex(random_bytes(5));
        $tenant = new Tenant('Empresa '.$suffix, 'empresa-'.$suffix);
        $legal = new LegalEntity($tenant, 'Empresa '.$suffix.' SAS', null, true);
        $branchA = new Branch($tenant, 'Principal', 'principal-'.$suffix, $legal, true);
        $branchB = new Branch($tenant, 'Secundaria', 'secundaria-'.$suffix, $legal);
        $sourceA = new InventorySource(
            $tenant, $legal, 'Principal', 'principal-'.$suffix,
            InventorySource::TYPE_BRANCH, $branchA,
        );
        $sourceB = new InventorySource(
            $tenant, $legal, 'Secundaria', 'secundaria-'.$suffix,
            InventorySource::TYPE_BRANCH, $branchB,
        );
        $owner = new User('crm-'. $suffix.'@example.test', 'Propietario');
        $member = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        foreach ([$tenant, $legal, $branchA, $branchB, $sourceA, $sourceB, $owner, $member] as $item) {
            $em->persist($item);
        }
        $em->flush();

        return [$tenant, $branchA, $branchB, $sourceA, $sourceB, $owner];
    }
}
