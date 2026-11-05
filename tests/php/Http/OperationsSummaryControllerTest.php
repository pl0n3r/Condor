<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Commercial\CommercialCatalogSeeder;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\Entity\SubscriptionConfiguration;
use App\Domain\Commercial\Entity\Vertical;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\BillOfMaterials;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\Entity\ProductionOrder;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OperationsSummaryControllerTest extends WebTestCase
{
    public function testSummaryIsTenantScopedReadOnlyEntitledAndDoesNotInventLabor(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $catalog = static::getContainer()->get(CommercialCatalogSeeder::class);
        self::assertInstanceOf(CommercialCatalogSeeder::class, $catalog);
        $catalog->seed();

        $salt = bin2hex(random_bytes(4));
        $tenant = new Tenant('Resumen '.$salt, 'summary-'.$salt);
        $legal = new LegalEntity($tenant, 'Resumen SAS', null, true);
        $branch = new Branch($tenant, 'Principal', 'principal-'.$salt, $legal, true);
        $owner = new User('summary-'.$salt.'@example.test', 'Owner sintético');
        $source = new InventorySource(
            $tenant, $legal, 'Principal', 'principal-'.$salt,
            InventorySource::TYPE_BRANCH, $branch,
        );
        $product = new Product($tenant, 'Producto sintético', 'synthetic-'.$salt);
        $variant = new ProductVariant($tenant, $product, 'SYN-'.$salt, 'Única');
        $material = new Material(
            $tenant, 'MAT-'.$salt, 'Material sintético', UnitOfMeasure::from('kg'),
        );
        $bom = new BillOfMaterials($tenant, $variant, 1, [[
            'material' => $material, 'quantity' => '1',
            'unit' => UnitOfMeasure::from('kg'),
        ]]);
        $order = new ProductionOrder($tenant, $bom, $variant, $source, 2);
        $order->complete(2, 'synthetic-completion-'.$salt);
        foreach ([
            $tenant, $legal, $branch, $owner,
            new Membership($tenant, $owner, Membership::ROLE_OWNER),
            $source, $product, $variant, $material, $bom, $order,
        ] as $object) {
            $em->persist($object);
        }
        $em->flush();

        $plan = $em->getRepository(Plan::class)->findOneBy(['key' => 'business']);
        $vertical = $em->getRepository(Vertical::class)->findOneBy(['key' => 'commerce']);
        $addOn = $em->getRepository(AddOn::class)->findOneBy(['key' => 'production-lite']);
        self::assertInstanceOf(Plan::class, $plan);
        self::assertInstanceOf(Vertical::class, $vertical);
        self::assertInstanceOf(AddOn::class, $addOn);
        $version = $em->getRepository(PlanVersion::class)->findOneBy([
            'plan' => $plan, 'version' => 1,
        ]);
        self::assertInstanceOf(PlanVersion::class, $version);
        $at = new DateTimeImmutable('2026-09-30T10:00:00Z');
        $subscription = Subscription::fromLifecycle(new SubscriptionLifecycle(
            $tenant->id(), $version, SubscriptionState::Active, $at,
        ), $at);
        $em->persist($subscription);
        $em->persist(new SubscriptionConfiguration(
            $subscription, $vertical,
            array_filter($version->limits(), static fn (mixed $v): bool => is_int($v)),
            [$addOn], $at,
        ));
        $em->flush();

        $path = '/api/v1/branches/'.$branch->id().'/production/operations-summary';
        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', $path.'?responsible=unknown');
        self::assertResponseStatusCodeSame(403);
        $client->loginUser($owner);
        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($branch->id(), $data['branch_id']);
        self::assertSame(1, $data['completed_orders']);
        self::assertSame(2, $data['completed_units']);
        self::assertEquals(100.0, $data['completion_percent']);
        self::assertSame($order->id(), $data['orders'][0]['id']);
        self::assertNull($data['orders'][0]['responsible_alias']);
        self::assertNull($data['orders'][0]['productivity_units_per_hour']);
        self::assertSame('UNKNOWN', $data['productivity_state']);

        $foreign = new Tenant('Ajeno '.$salt, 'foreign-'.$salt);
        $foreignLegal = new LegalEntity($foreign, 'Ajena SAS', null, true);
        $foreignBranch = new Branch(
            $foreign, 'Ajena', 'foreign-'.$salt, $foreignLegal, true,
        );
        foreach ([$foreign, $foreignLegal, $foreignBranch] as $object) {
            $em->persist($object);
        }
        $em->flush();
        $client->request(
            'GET',
            '/api/v1/branches/'.$foreignBranch->id().'/production/operations-summary',
        );
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $path.'?responsible=real');
        self::assertResponseStatusCodeSame(400);
        // No hay POST: una solicitud sin CSRF nunca puede alterar la orden.
        $client->request('POST', $path);
        self::assertResponseStatusCodeSame(405);

        $viewer = new User('viewer-'.$salt.'@example.test', 'Viewer');
        $membership = new Membership($tenant, $viewer, 'ADMIN');
        $role = new Role($tenant, 'Sin permiso de lectura', ['inventory.update']);
        foreach ([
            $viewer, $membership, $role,
            new BranchRoleAssignment($membership, $branch, $role),
        ] as $object) {
            $em->persist($object);
        }
        $em->flush();
        $client->loginUser($viewer);
        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(403);
    }
}
