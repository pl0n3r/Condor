<?php

declare(strict_types=1);

namespace App\Tests\Application\Inventory;

use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventoryMovement;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Inventory\Entity\InventoryTransfer;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Integration in the isolated test kernel and database; no production writes. */
final class TransferProposalsHttpTest extends WebTestCase
{
    public function testOwnerProposesThenExplicitlyConfirmsWithIdempotentLedger(): void
    {
        $client = static::createClient();
        $em = $this->entityManager();
        [$tenant, $destination, $origin, $owner, $variant, $sourceTo, $sourceFrom] =
            $this->fixture($em, 30, 4);

        $client->loginUser($owner);
        $url = '/api/v1/branches/'.$destination->id().'/inventory/branch-transfers/proposals';
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        self::assertSame($destination->id(), $data['branch_id']);
        self::assertCount(1, $data['proposals']);
        $proposal = $data['proposals'][0];
        self::assertSame($sourceFrom->id(), $proposal['source_from_id']);
        self::assertSame($sourceTo->id(), $proposal['source_to_id']);
        self::assertSame($variant->id(), $proposal['variant_id']);
        self::assertSame(8, $proposal['quantity']);
        self::assertSame(26, $proposal['origin_excess']);
        self::assertSame(
            0,
            $em->getRepository(InventoryTransfer::class)->count(['tenant' => $tenant]),
            'GET may only propose: it never moves stock',
        );

        $csrf = $this->csrf($client);
        $transferUrl = '/api/v1/branches/'.$origin->id().'/inventory/branch-transfers';
        $payload = [
            'source_from_id' => $proposal['source_from_id'],
            'source_to_id' => $proposal['source_to_id'],
            'variant_id' => $proposal['variant_id'],
            'quantity' => $proposal['quantity'],
            'idempotency_key' => 'proposal-confirm-'.bin2hex(random_bytes(5)),
        ];
        $client->jsonRequest('POST', $transferUrl, $payload, ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
        $first = $this->json($client)['transfer'];
        $client->jsonRequest('POST', $transferUrl, $payload, ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($first['id'], $this->json($client)['transfer']['id']);
        self::assertSame(
            1,
            $em->getRepository(InventoryTransfer::class)->count(['tenant' => $tenant]),
        );
        $em->clear();
        self::assertSame(22, $em->getRepository(InventoryBalance::class)
            ->findOneBy(['tenant' => $tenant, 'source' => $sourceFrom, 'variant' => $variant])
            ->quantity());
        self::assertSame(10, $em->getRepository(InventoryBalance::class)
            ->findOneBy(['tenant' => $tenant, 'source' => $sourceTo, 'variant' => $variant])
            ->quantity());
    }

    public function testInsufficientExcessAndMissingBranchPermissionDoNotLeakDonors(): void
    {
        $client = static::createClient();
        $em = $this->entityManager();
        [$tenant, $destination, , $owner] = $this->fixture($em, 7, 4);

        $client->loginUser($owner);
        $url = '/api/v1/branches/'.$destination->id().'/inventory/branch-transfers/proposals';
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json($client)['proposals']);

        // Even if a donor has sufficient stock, a user with access to only
        // the destination must not see its name, source ID or balance.
        [$tenant2, $destination2] = $this->fixture($em, 30, 4);
        $staff = new User('proposal-staff-'.bin2hex(random_bytes(5)).'@example.test', 'Staff');
        $membership = new Membership($tenant2, $staff, 'STAFF');
        $role = new Role($tenant2, 'Propuestas', ['inventory.view', 'inventory.update']);
        $assignment = new BranchRoleAssignment($membership, $destination2, $role);
        foreach ([$staff, $membership, $role, $assignment] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($staff);
        $client->request(
            'GET',
            '/api/v1/branches/'.$destination2->id().'/inventory/branch-transfers/proposals',
        );
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json($client)['proposals']);
    }

    /**
     * @return array{Tenant,Branch,Branch,User,ProductVariant,InventorySource,InventorySource}
     */
    private function fixture(EntityManagerInterface $em, int $originQty, int $originConsumption): array
    {
        $id = bin2hex(random_bytes(4));
        $tenant = new Tenant('Proposal '.$id, 'proposal-'.$id);
        $entity = new LegalEntity($tenant, 'Proposal '.$id.' SAS', null, true);
        $destination = new Branch($tenant, 'Destino', 'destino-'.$id, $entity);
        $origin = new Branch($tenant, 'Origen', 'origen-'.$id, $entity);
        $owner = new User('proposal-owner-'.$id.'@example.test', 'Owner');
        $membership = new Membership($tenant, $owner, Membership::ROLE_OWNER);
        $product = new Product($tenant, 'Producto '.$id, 'producto-'.$id);
        $variant = new ProductVariant($tenant, $product, 'SKU-'.$id, 'Unidad');
        $to = new InventorySource($tenant, $entity, 'Destino', 'stock-destino-'.$id,
            InventorySource::TYPE_BRANCH, $destination);
        $from = new InventorySource($tenant, $entity, 'Origen', 'stock-origen-'.$id,
            InventorySource::TYPE_BRANCH, $origin);
        $toBalance = new InventoryBalance($tenant, $to, $variant, 2);
        $fromBalance = new InventoryBalance($tenant, $from, $variant, $originQty);
        // Historical sale, not a stock transfer or adjustment.
        $consumption = new InventoryMovement($tenant, $to, $variant,
            InventoryMovement::TYPE_ORDER_CONSUMPTION, -10, 2, null);
        $donorConsumption = new InventoryMovement($tenant, $from, $variant,
            InventoryMovement::TYPE_ORDER_CONSUMPTION, -$originConsumption, $originQty, null);
        foreach ([$tenant, $entity, $destination, $origin, $owner, $membership, $product,
            $variant, $to, $from, $toBalance, $fromBalance,
            $consumption, $donorConsumption] as $record) {
            $em->persist($record);
        }
        $em->flush();

        return [$tenant, $destination, $origin, $owner, $variant, $to, $from];
    }

    private function entityManager(): EntityManagerInterface
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        return $manager;
    }

    /** @return array<string,mixed> */
    private function json(KernelBrowser $client): array
    {
        $data = json_decode((string) $client->getResponse()->getContent(),
            true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    private function csrf(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($token);
        return $token;
    }
}
