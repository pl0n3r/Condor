<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\CommercialCategory;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CommerceControllerTest extends WebTestCase
{
    public function testOwnerBuildsCommercialPricingFlow(): void
    {
        $client = static::createClient();
        $em = $this->entityManager();
        [$tenant, $branch, $owner] = $this->fixture($em);

        $product = new Product($tenant, 'Body básico', 'body-basico');
        $variant = new ProductVariant(
            $tenant,
            $product,
            'BODY-NEGRO-U',
            'Negro / Única',
        );
        $em->persist($product);
        $em->persist($variant);
        $em->flush();

        $client->loginUser($owner);
        $csrf = $this->csrf($client);

        $categoryUrl = '/api/v1/branches/'.$branch->id()
            .'/customers/categories';
        $client->jsonRequest(
            'POST',
            $categoryUrl,
            ['name' => 'Mayorista', 'slug' => 'mayorista'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $categoryId = $this->json($client)['category']['id'];

        $customersUrl = '/api/v1/branches/'.$branch->id().'/customers';
        $client->jsonRequest(
            'POST',
            $customersUrl,
            [
                'name' => 'Cliente Distribuidor',
                'email' => 'compras@example.test',
                'category_id' => $categoryId,
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $customerId = $this->json($client)['customer']['id'];

        $listsUrl = '/api/v1/branches/'.$branch->id().'/pricing/lists';
        $client->jsonRequest(
            'POST',
            $listsUrl,
            ['name' => 'Mayorista', 'slug' => 'mayorista'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $list = $this->json($client)['price_list'];
        $listId = $list['id'];
        self::assertSame('COP', $list['currency']);

        $client->jsonRequest(
            'PATCH',
            '/api/v1/branches/'.$branch->id()
                .'/pricing/categories/'.$categoryId,
            ['preferred_price_list_id' => $listId],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseIsSuccessful();

        $client->request(
            'GET',
            '/api/v1/branches/'.$branch->id()
                .'/pricing/effective?variant='.$variant->id()
                .'&customer='.$customerId,
        );
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'no tiene precio',
            mb_strtolower(
                (string) ($this->json($client)['message'] ?? ''),
                'UTF-8',
            ),
        );

        $client->jsonRequest(
            'PUT',
            '/api/v1/branches/'.$branch->id()
                .'/pricing/lists/'.$listId
                .'/variants/'.$variant->id(),
            ['amount_minor' => 100000],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);

        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/pricing/rules',
            [
                'price_list_id' => $listId,
                'category_id' => $categoryId,
                'name' => 'Mayorista 15 mil',
                'priority' => 100,
                'discount_type' => 'fixed',
                'discount_value' => 15000,
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $ruleId = $this->json($client)['rule']['id'];

        $client->request(
            'GET',
            '/api/v1/branches/'.$branch->id()
                .'/pricing/effective?variant='.$variant->id()
                .'&customer='.$customerId,
        );
        self::assertResponseIsSuccessful();
        $effective = $this->json($client)['effective_price'];
        self::assertSame(100000, $effective['base_amount_minor']);
        self::assertSame(85000, $effective['amount_minor']);
        self::assertSame('COP', $effective['currency']);
        self::assertSame($listId, $effective['price_list_id']);
        self::assertSame($ruleId, $effective['rule_id']);

        $client->request('GET', $customersUrl);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->json($client)['customers']);

        $client->request(
            'GET',
            '/api/v1/branches/'.$branch->id().'/pricing',
        );
        self::assertResponseIsSuccessful();
        $pricing = $this->json($client);
        self::assertCount(1, $pricing['price_lists']);
        self::assertCount(1, $pricing['variant_prices']);
        self::assertCount(1, $pricing['rules']);

        $auditCount = (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM condor_audit_event '
            .'WHERE tenant_id = ? AND action IN (?, ?, ?, ?, ?)',
            [
                $tenant->id(),
                'commercial_category.created',
                'customer.created',
                'price_list.created',
                'variant_price.created',
                'price_rule.created',
            ],
        );
        self::assertSame(5, $auditCount);
    }

    public function testDelegatedViewerCannotMutateCustomersOrPricing(): void
    {
        $client = static::createClient();
        $em = $this->entityManager();
        [$tenant, $branch, $user, $membership] = $this->fixture(
            $em,
            'ADMIN',
            true,
        );
        $viewer = new Role(
            $tenant,
            'Consulta comercial',
            ['customers.view', 'pricing.view'],
        );
        $em->persist($viewer);
        $em->persist(new BranchRoleAssignment(
            $membership,
            $branch,
            $viewer,
        ));
        $em->flush();

        $client->loginUser($user);
        $csrf = $this->csrf($client);

        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id()
                .'/customers/categories',
            ['name' => 'No permitido', 'slug' => 'no-permitido'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(403);

        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/pricing/lists',
            ['name' => 'No permitida', 'slug' => 'no-permitida'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(403);

        $client->request(
            'GET',
            '/api/v1/branches/'.$branch->id().'/customers',
        );
        self::assertResponseIsSuccessful();
        $client->request(
            'GET',
            '/api/v1/branches/'.$branch->id().'/pricing',
        );
        self::assertResponseIsSuccessful();
    }

    public function testCrossTenantCommercialIdsAreNotResolved(): void
    {
        $client = static::createClient();
        $em = $this->entityManager();
        [$tenant, $branch, $owner] = $this->fixture($em);

        $other = new Tenant(
            'Otra empresa',
            'otra-'.bin2hex(random_bytes(4)),
        );
        $otherCategory = new CommercialCategory(
            $other,
            'Ajena',
            'ajena',
        );
        $otherList = new PriceList($other, 'Ajena', 'ajena');
        $otherProduct = new Product($other, 'Ajeno', 'ajeno');
        $otherVariant = new ProductVariant(
            $other,
            $otherProduct,
            'AJENO-1',
            'Ajena',
        );
        foreach (
            [
                $other,
                $otherCategory,
                $otherList,
                $otherProduct,
                $otherVariant,
            ] as $entity
        ) {
            $em->persist($entity);
        }
        $ownList = new PriceList($tenant, 'Propia', 'propia');
        $em->persist($ownList);
        $em->flush();

        $client->loginUser($owner);
        $csrf = $this->csrf($client);

        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/customers',
            [
                'name' => 'Escape',
                'category_id' => $otherCategory->id(),
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(404);
        self::assertNull(
            $em->getRepository(Customer::class)->findOneBy([
                'tenant' => $tenant,
                'name' => 'Escape',
            ]),
        );

        $client->jsonRequest(
            'PUT',
            '/api/v1/branches/'.$branch->id()
                .'/pricing/lists/'.$ownList->id()
                .'/variants/'.$otherVariant->id(),
            ['amount_minor' => 1000],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(404);
    }

    public function testDatabaseRejectsCrossTenantCommercialReferences(): void
    {
        $em = $this->entityManager();
        [$tenant] = $this->fixture($em);
        $other = new Tenant(
            'Otra empresa',
            'otra-db-'.bin2hex(random_bytes(4)),
        );
        $otherCategory = new CommercialCategory(
            $other,
            'Otra',
            'otra',
        );
        $em->persist($other);
        $em->persist($otherCategory);
        $em->flush();

        $this->expectException(
            ForeignKeyConstraintViolationException::class,
        );
        $em->getConnection()->executeStatement(
            'INSERT INTO condor_customer '
            .'(id, tenant_id, commercial_category_id, name, active, '
            .'created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())',
            [
                UlidFactory::new(),
                $tenant->id(),
                $otherCategory->id(),
                'Inválido',
            ],
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($payload);

        return $payload;
    }

    private function csrf(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $token = $crawler
            ->filter('#condor-admin-root')
            ->attr('data-access-token');
        self::assertIsString($token);
        self::assertNotSame('', $token);

        return $token;
    }

    /**
     * @return array{0: Tenant, 1: Branch, 2: User, 3?: Membership}
     */
    private function fixture(
        EntityManagerInterface $em,
        string $role = Membership::ROLE_OWNER,
        bool $withMembership = false,
    ): array {
        $suffix = strtolower(bin2hex(random_bytes(4)));
        $tenant = new Tenant(
            'Empresa '.$suffix,
            'empresa-'.$suffix,
        );
        $branch = new Branch(
            $tenant,
            'Principal',
            'principal-'.$suffix,
            null,
            true,
        );
        $user = new User(
            'commerce-'.$suffix.'@example.test',
            'Usuario',
        );
        $membership = new Membership($tenant, $user, $role);
        foreach (
            [$tenant, $branch, $user, $membership] as $entity
        ) {
            $em->persist($entity);
        }
        $em->flush();

        return $withMembership
            ? [$tenant, $branch, $user, $membership]
            : [$tenant, $branch, $user];
    }
}
