<?php

declare(strict_types=1);

namespace App\Tests\Application\Commercial;

use App\Application\Commercial\BillableUsage;
use App\Application\Commercial\EntitlementSnapshot;
use App\Application\Commercial\ExecutionContext;
use App\Application\Commercial\TenantUsageReservation;
use App\Application\Commercial\UsageLimitExceeded;
use App\Application\Identity\AddTenantUser;
use App\Application\Organization\AddTenantBranch;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BillableUsageTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BillableUsage $usage;
    private AddTenantUser $addUser;
    private AddTenantBranch $addBranch;
    private string $suffix;
    /** @var list<Tenant> */
    private array $tenants = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->usage = new BillableUsage($em);
        $reservation = new TenantUsageReservation($em);
        $this->addUser = new AddTenantUser($em, $reservation, $this->usage);
        $this->addBranch = new AddTenantBranch($em, $reservation, $this->usage);
        $this->suffix = bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        foreach ($this->tenants as $tenant) {
            $tenantId = $tenant->id();
            $this->em->clear();
            $users = $this->em->createQuery(
                'SELECT IDENTITY(m.user) FROM '.Membership::class.' m WHERE IDENTITY(m.tenant) = :t',
            )->setParameter('t', $tenantId)->getSingleColumnResult();
            $this->em->createQuery('DELETE '.Membership::class.' m WHERE IDENTITY(m.tenant) = :t')
                ->setParameter('t', $tenantId)->execute();
            $this->em->createQuery('DELETE '.Branch::class.' b WHERE IDENTITY(b.tenant) = :t')
                ->setParameter('t', $tenantId)->execute();
            if ($users !== []) {
                $this->em->createQuery('DELETE '.User::class.' u WHERE u.id IN (:ids)')
                    ->setParameter('ids', $users)->execute();
            }
            $this->em->createQuery('DELETE '.Tenant::class.' t WHERE t.id = :t')
                ->setParameter('t', $tenantId)->execute();
        }
        parent::tearDown();
    }

    public function testBootstrapOwnerAndInitialBranchAreNotBillable(): void
    {
        $tenant = $this->tenantWithBootstrap('a');

        self::assertSame(0, $this->usage->seats($tenant->id()));
        self::assertSame(0, $this->usage->locations($tenant->id()));
    }

    public function testPostBootstrapAdditionsConsumeSeatsAndLocations(): void
    {
        $tenant = $this->tenantWithBootstrap('b');
        $ctx = $this->context($tenant, users: 3, locations: 2);

        $this->addUser->add($ctx, 'Uno-'.$this->suffix.'@example.test', 'Uno', 'STAFF');
        $this->addBranch->add($ctx, 'Norte', 'norte-'.$this->suffix);

        self::assertSame(1, $this->usage->seats($tenant->id()));
        self::assertSame(1, $this->usage->locations($tenant->id()));
    }

    public function testSeatLimitRejectsTheNextUserAndPersistsNothing(): void
    {
        $tenant = $this->tenantWithBootstrap('c');
        $ctx = $this->context($tenant, users: 1, locations: 1);
        $this->addUser->add($ctx, 'ok-'.$this->suffix.'@example.test', 'Ok', 'STAFF');

        try {
            $this->addUser->add($ctx, 'over-'.$this->suffix.'@example.test', 'Over', 'STAFF');
            self::fail('Se esperaba UsageLimitExceeded.');
        } catch (UsageLimitExceeded $e) {
            self::assertSame('users', $e->metric);
            self::assertSame(1, $e->limit);
        }

        self::assertSame(1, $this->usage->seats($tenant->id()));
        self::assertNull($this->em->getRepository(User::class)->findOneBy([
            'email' => 'over-'.$this->suffix.'@example.test',
        ]));
    }

    public function testLocationLimitRejectsTheNextBranch(): void
    {
        $tenant = $this->tenantWithBootstrap('d');
        $ctx = $this->context($tenant, users: 5, locations: 1);
        $this->addBranch->add($ctx, 'Uno', 'uno-'.$this->suffix);

        $this->expectException(UsageLimitExceeded::class);
        $this->addBranch->add($ctx, 'Dos', 'dos-'.$this->suffix);
    }

    public function testRetriesAreIdempotentAndDoNotConsumeCapacity(): void
    {
        $tenant = $this->tenantWithBootstrap('e');
        $ctx = $this->context($tenant, users: 1, locations: 1);

        $first = $this->addUser->add($ctx, 'dup-'.$this->suffix.'@example.test', 'Dup', 'STAFF');
        $again = $this->addUser->add($ctx, ' DUP-'.$this->suffix.'@EXAMPLE.test ', 'Dup', 'STAFF');
        $branch = $this->addBranch->add($ctx, 'Sede', 'sede-'.$this->suffix);
        $branchAgain = $this->addBranch->add($ctx, 'Sede', 'sede-'.$this->suffix);

        self::assertSame($first->id(), $again->id());
        self::assertSame($branch->id(), $branchAgain->id());
        self::assertSame(1, $this->usage->seats($tenant->id()));
        self::assertSame(1, $this->usage->locations($tenant->id()));
    }

    public function testOwnerRoleCannotBeAddedAsSeatAndInitialBranchIsNotRecreated(): void
    {
        $tenant = $this->tenantWithBootstrap('f');
        $ctx = $this->context($tenant, users: 5, locations: 5);

        try {
            $this->addUser->add($ctx, 'o-'.$this->suffix.'@example.test', 'O', Membership::ROLE_OWNER);
            self::fail('OWNER no es un asiento facturable.');
        } catch (DomainException) {
        }

        $this->expectException(DomainException::class);
        $this->addBranch->add($ctx, 'Principal', 'principal-f-'.$this->suffix);
    }

    public function testUsageIsIsolatedPerTenantAndSnapshotMustMatch(): void
    {
        $a = $this->tenantWithBootstrap('g');
        $b = $this->tenantWithBootstrap('h');
        $this->addUser->add($this->context($a, users: 3, locations: 1), 'a-'.$this->suffix.'@example.test', 'A', 'STAFF');

        self::assertSame(1, $this->usage->seats($a->id()));
        self::assertSame(0, $this->usage->seats($b->id()));

        $this->expectException(DomainException::class);
        new ExecutionContext($b->id(), $this->snapshot($a, 3, 1), 'test');
    }

    public function testNullLimitMeansNoLimitDefinedAndIsNotInvented(): void
    {
        $tenant = $this->tenantWithBootstrap('i');
        $ctx = new ExecutionContext($tenant->id(), $this->snapshot($tenant, null, null), 'test');

        $this->addUser->add($ctx, 'n1-'.$this->suffix.'@example.test', 'N1', 'STAFF');
        $this->addUser->add($ctx, 'n2-'.$this->suffix.'@example.test', 'N2', 'STAFF');

        self::assertSame(2, $this->usage->seats($tenant->id()));
    }

    private function tenantWithBootstrap(string $tag): Tenant
    {
        $tenant = new Tenant('Billable '.$tag.$this->suffix, 'billable-'.$tag.'-'.$this->suffix);
        $owner = new User('owner-'.$tag.'-'.$this->suffix.'@example.test', 'Owner');
        $this->em->persist($tenant);
        $this->em->persist($owner);
        $this->em->persist(new Membership($tenant, $owner, Membership::ROLE_OWNER));
        $this->em->persist(new Branch($tenant, 'Principal', 'principal-'.$tag.'-'.$this->suffix, null, true));
        $this->em->flush();
        $this->tenants[] = $tenant;

        return $tenant;
    }

    private function context(Tenant $tenant, int $users, int $locations): ExecutionContext
    {
        return new ExecutionContext($tenant->id(), $this->snapshot($tenant, $users, $locations), 'test');
    }

    private function snapshot(Tenant $tenant, ?int $users, ?int $locations): EntitlementSnapshot
    {
        return new EntitlementSnapshot(
            $tenant->id(),
            'test-plan',
            1,
            'general',
            [],
            [],
            ['companies' => 1, 'users' => $users, 'locations' => $locations],
            [],
            new DateTimeImmutable('2026-11-05T12:00:00Z'),
        );
    }
}
