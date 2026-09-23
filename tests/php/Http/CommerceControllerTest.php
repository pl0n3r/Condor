<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\CommercialCategory;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\PriceRule;
use App\Domain\Commerce\Entity\VariantPrice;
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
        $validation = $this->json($client);
        self::assertSame('validation_error', $validation['error']);
        self::assertSame(
            'Los datos enviados no son válidos.',
            $validation['message'],
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

    public function testPriceRuleValidityUsesUtcAndRequiresTimezoneOffset(): void
    {
        $client = static::createClient();
        $em = $this->entityManager();
        [, $branch, $owner] = $this->fixture($em);
        $client->loginUser($owner);
        $csrf = $this->csrf($client);

        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/pricing/lists',
            ['name' => 'Temporal', 'slug' => 'temporal'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $listId = $this->json($client)['price_list']['id'];

        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/pricing/rules',
            [
                'price_list_id' => $listId,
                'category_id' => null,
                'name' => 'Vigencia con offset',
                'priority' => 20,
                'discount_type' => 'percentage',
                'discount_value' => 1000,
                'valid_from' => '2026-09-23T08:15:00-05:00',
                'valid_until' => '2026-09-23T10:45:00-05:00',
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $created = $this->json($client)['rule'];
        $ruleId = $created['id'];
        self::assertSame(
            '2026-09-23T13:15:00+00:00',
            $created['valid_from'],
        );
        self::assertSame(
            '2026-09-23T15:45:00+00:00',
            $created['valid_until'],
        );

        $stored = $em->getConnection()->fetchAssociative(
            'SELECT valid_from, valid_until FROM condor_price_rule WHERE id = ?',
            [$ruleId],
        );
        self::assertIsArray($stored);
        self::assertSame('2026-09-23 13:15:00', $stored['valid_from']);
        self::assertSame('2026-09-23 15:45:00', $stored['valid_until']);

        $client->request(
            'GET',
            '/api/v1/branches/'.$branch->id().'/pricing',
        );
        self::assertResponseIsSuccessful();
        $rules = array_values(array_filter(
            $this->json($client)['rules'],
            static fn (array $rule): bool => $rule['id'] === $ruleId,
        ));
        self::assertCount(1, $rules);
        self::assertSame(
            '2026-09-23T13:15:00+00:00',
            $rules[0]['valid_from'],
        );
        self::assertSame(
            '2026-09-23T15:45:00+00:00',
            $rules[0]['valid_until'],
        );

        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/pricing/rules',
            [
                'price_list_id' => $listId,
                'category_id' => null,
                'name' => 'Vigencia sin offset',
                'priority' => 10,
                'discount_type' => 'fixed',
                'discount_value' => 1000,
                'valid_from' => '2026-09-23T08:15:00',
                'valid_until' => null,
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest(
            'POST',
            '/api/v1/branches/'.$branch->id().'/pricing/rules',
            [
                'price_list_id' => $listId,
                'category_id' => null,
                'name' => 'Fecha de calendario inválida',
                'priority' => 5,
                'discount_type' => 'fixed',
                'discount_value' => 1000,
                'valid_from' => '2026-02-30T10:00:00+00:00',
                'valid_until' => null,
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(422);
    }

    public function testOwnerUpdatesAndDeactivatesCommercialRecordsWithAudit(): void
    {
        $client = static::createClient();
        $em = $this->entityManager();
        [$tenant, $branch, $owner] = $this->fixture($em);
        $client->loginUser($owner);
        $csrf = $this->csrf($client);
        $suffix = strtolower(bin2hex(random_bytes(4)));

        $categoryUrl = '/api/v1/branches/'.$branch->id()
            .'/customers/categories';
        $client->jsonRequest(
            'POST',
            $categoryUrl,
            ['name' => 'Categoría '.$suffix, 'slug' => 'categoria-'.$suffix],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $categoryId = $this->json($client)['category']['id'];

        $customerUrl = '/api/v1/branches/'.$branch->id().'/customers';
        $client->jsonRequest(
            'POST',
            $customerUrl,
            [
                'name' => 'Cliente '.$suffix,
                'email' => 'cliente-'.$suffix.'@example.test',
                'category_id' => $categoryId,
            ],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $customerId = $this->json($client)['customer']['id'];

        $listUrl = '/api/v1/branches/'.$branch->id().'/pricing/lists';
        $client->jsonRequest(
            'POST',
            $listUrl,
            ['name' => 'Lista '.$suffix, 'slug' => 'lista-'.$suffix],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(201);
        $listId = $this->json($client)['price_list']['id'];

        $client->jsonRequest(
            'PATCH',
            $categoryUrl.'/'.$categoryId,
            ['name' => 'Categoría editada '.$suffix],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseIsSuccessful();
        self::assertSame(
            'Categoría editada '.$suffix,
            $this->json($client)['category']['name'],
        );

        $client->jsonRequest(
            'PATCH',
            $customerUrl.'/'.$customerId,
            ['email' => 'editado-'.$suffix.'@example.test'],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseIsSuccessful();
        self::assertSame(
            'editado-'.$suffix.'@example.test',
            $this->json($client)['customer']['email'],
        );

        $client->jsonRequest(
            'PATCH',
            $listUrl.'/'.$listId,
            ['name' => 'Lista editada '.$suffix],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseIsSuccessful();
        self::assertSame(
            'Lista editada '.$suffix,
            $this->json($client)['price_list']['name'],
        );

        $client->request(
            'DELETE',
            $customerUrl.'/'.$customerId,
            server: ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(204);

        $client->request(
            'DELETE',
            $categoryUrl.'/'.$categoryId,
            server: ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(204);

        $client->request(
            'DELETE',
            $listUrl.'/'.$listId,
            server: ['HTTP_X_CSRF_TOKEN' => $csrf],
        );
        self::assertResponseStatusCodeSame(204);

        $auditCount = (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM condor_audit_event '
            .'WHERE tenant_id = ? AND action IN (?, ?, ?, ?, ?, ?)',
            [
                $tenant->id(),
                'commercial_category.updated',
                'customer.updated',
                'price_list.updated',
                'customer.deactivated',
                'commercial_category.deactivated',
                'price_list.deactivated',
            ],
        );
        self::assertSame(6, $auditCount);
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

    public function testVariantPriceUpdateAuditsPreviousAndNewAmounts(): void
    {
        $client = static::createClient();
        $em = $this->entityManager();
        [$tenant, $branch, $owner] = $this->fixture($em);
        $suffix = strtolower(bin2hex(random_bytes(4)));

        $list = new PriceList(
            $tenant,
            'Lista audit '.$suffix,
            'lista-audit-'.$suffix,
        );
        $product = new Product(
            $tenant,
            'Producto audit '.$suffix,
            'producto-audit-'.$suffix,
        );
        $variant = new ProductVariant(
            $tenant,
            $product,
            'AUDIT-'.strtoupper($suffix),
            'Única',
        );
        $price = new VariantPrice(
            $tenant,
            $list,
            $variant,
            100000,
        );
        foreach ([$list, $product, $variant, $price] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($owner);
        $csrf = $this->csrf($client);
        $client->jsonRequest(
            'PUT',
            '/api/v1/branches/'.$branch->id()
                .'/pricing/lists/'.$list->id()
                .'/variants/'.$variant->id(),
            ['amount_minor' => 125000],
            ['HTTP_X_CSRF_TOKEN' => $csrf],
        );

        self::assertResponseIsSuccessful();
        self::assertSame(
            125000,
            $this->json($client)['variant_price']['amount_minor'],
        );

        $storedContext = $em->getConnection()->fetchOne(
            'SELECT context FROM condor_audit_event '
            .'WHERE tenant_id = ? AND action = ? AND entity_id = ? '
            .'ORDER BY created_at DESC LIMIT 1',
            [
                $tenant->id(),
                'variant_price.updated',
                $price->id(),
            ],
        );
        self::assertIsString($storedContext);
        $auditContext = json_decode(
            $storedContext,
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($auditContext);
        self::assertSame(
            100000,
            $auditContext['previous_amount_minor'] ?? null,
        );
        self::assertSame(
            125000,
            $auditContext['amount_minor'] ?? null,
        );
        self::assertSame(
            $list->id(),
            $auditContext['price_list_id'] ?? null,
        );
        self::assertSame(
            $variant->id(),
            $auditContext['variant_id'] ?? null,
        );
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

    public function testDatabaseRejectsCrossTenantVariantPriceReference(): void
    {
        $em = $this->entityManager();
        [$tenant] = $this->fixture($em);
        $list = new PriceList(
            $tenant,
            'Propia DB',
            'propia-db-'.bin2hex(random_bytes(4)),
        );

        $other = new Tenant(
            'Otra empresa',
            'otra-price-db-'.bin2hex(random_bytes(4)),
        );
        $otherProduct = new Product(
            $other,
            'Producto ajeno',
            'producto-ajeno-'.bin2hex(random_bytes(4)),
        );
        $otherVariant = new ProductVariant(
            $other,
            $otherProduct,
            'AJENO-DB-'.bin2hex(random_bytes(3)),
            'Ajena',
        );
        foreach ([$list, $other, $otherProduct, $otherVariant] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $this->expectException(
            ForeignKeyConstraintViolationException::class,
        );
        $em->getConnection()->executeStatement(
            'INSERT INTO condor_variant_price '
            .'(id, tenant_id, price_list_id, variant_id, amount_minor, '
            .'created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [
                UlidFactory::new(),
                $tenant->id(),
                $list->id(),
                $otherVariant->id(),
                1000,
            ],
        );
    }

    public function testDeletingTenantCascadesCommercialGraph(): void
    {
        $em = $this->entityManager();
        $suffix = strtolower(bin2hex(random_bytes(4)));
        $tenant = new Tenant(
            'Empresa cascade '.$suffix,
            'empresa-cascade-'.$suffix,
        );
        $category = new CommercialCategory(
            $tenant,
            'Mayorista cascade',
            'mayorista-cascade-'.$suffix,
        );
        $list = new PriceList(
            $tenant,
            'Lista cascade',
            'lista-cascade-'.$suffix,
        );
        $category->assignPreferredPriceList($list);
        $customer = new Customer(
            $tenant,
            'Cliente cascade',
            commercialCategory: $category,
        );
        $product = new Product(
            $tenant,
            'Producto cascade',
            'producto-cascade-'.$suffix,
        );
        $variant = new ProductVariant(
            $tenant,
            $product,
            'CASCADE-'.strtoupper($suffix),
            'Única',
        );
        $variantPrice = new VariantPrice(
            $tenant,
            $list,
            $variant,
            100000,
        );
        $rule = new PriceRule(
            $tenant,
            $list,
            'Regla cascade',
            1,
            PriceRule::TYPE_FIXED,
            1000,
            $category,
        );

        foreach ([
            $tenant,
            $category,
            $list,
            $customer,
            $product,
            $variant,
            $variantPrice,
            $rule,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $tenantId = $tenant->id();
        $em->clear();

        self::assertSame(
            1,
            $em->getConnection()->executeStatement(
                'DELETE FROM condor_tenant WHERE id = ?',
                [$tenantId],
            ),
        );

        foreach ([
            'condor_customer',
            'condor_commercial_category',
            'condor_price_rule',
            'condor_variant_price',
            'condor_price_list',
        ] as $table) {
            self::assertSame(
                0,
                (int) $em->getConnection()->fetchOne(
                    'SELECT COUNT(*) FROM '.$table.' WHERE tenant_id = ?',
                    [$tenantId],
                ),
                $table.' debe borrarse por cascada junto con el tenant.',
            );
        }
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
