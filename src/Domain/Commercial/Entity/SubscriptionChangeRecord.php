<?php

declare(strict_types=1);

namespace App\Domain\Commercial\Entity;

use App\Domain\Commercial\EntitlementOverride;
use App\Domain\Commercial\SubscriptionChange;
use App\Shared\Id\UlidFactory;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'condor_commercial_subscription_change')]
#[ORM\Index(
    name: 'idx_commercial_sub_change_tenant_requested',
    columns: ['tenant_id', 'requested_at'],
)]
#[ORM\Index(
    name: 'idx_commercial_sub_change_tenant_status_effective',
    columns: ['tenant_id', 'status', 'effective_at'],
)]
final class SubscriptionChangeRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'string', length: 120)]
    private string $tenantId;

    #[ORM\ManyToOne(targetEntity: PlanVersion::class)]
    #[ORM\JoinColumn(
        name: 'current_plan_version_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private PlanVersion $currentPlanVersion;

    #[ORM\ManyToOne(targetEntity: PlanVersion::class)]
    #[ORM\JoinColumn(
        name: 'target_plan_version_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private PlanVersion $targetPlanVersion;

    #[ORM\Column(type: 'string', length: 16)]
    private string $direction;

    #[ORM\Column(type: 'string', length: 32)]
    private string $status;

    #[ORM\Column(name: 'requested_at', type: 'string', length: 32)]
    private string $requestedAt;

    #[ORM\Column(name: 'effective_at', type: 'string', length: 32, nullable: true)]
    private ?string $effectiveAt = null;

    /** @var array<array-key,mixed> */
    #[ORM\Column(type: 'json')]
    private array $blockers;

    /** @var array<array-key,mixed> */
    #[ORM\Column(name: 'override_snapshots', type: 'json')]
    private array $overrideSnapshots;

    #[ORM\Column(name: 'requested_by', type: 'string', length: 26, nullable: true)]
    private ?string $requestedBy = null;

    #[ORM\Column(name: 'audit_reason', type: 'string', length: 500, nullable: true)]
    private ?string $auditReason = null;

    /** @var Collection<int, AddOn> */
    #[ORM\ManyToMany(targetEntity: AddOn::class)]
    #[ORM\JoinTable(name: 'condor_commercial_subscription_change_addon')]
    #[ORM\JoinColumn(
        name: 'subscription_change_id',
        referencedColumnName: 'id',
        onDelete: 'CASCADE',
    )]
    #[ORM\InverseJoinColumn(
        name: 'addon_id',
        referencedColumnName: 'id',
        onDelete: 'RESTRICT',
    )]
    private Collection $addOns;

    private function __construct()
    {
    }

    public static function fromChange(SubscriptionChange $change): self
    {
        $record = new self();
        $record->id = UlidFactory::new();
        $record->tenantId = $change->tenantId();
        $record->currentPlanVersion = $change->currentPlan();
        $record->targetPlanVersion = $change->targetPlan();
        $record->direction = $change->direction();
        $record->status = $change->status();
        $record->requestedAt = self::formatExact($change->requestedAt());
        $record->effectiveAt = $change->effectiveAt() instanceof \DateTimeImmutable
            ? self::formatExact($change->effectiveAt())
            : null;
        $record->blockers = $change->blockers();
        $record->overrideSnapshots = array_map(
            static function (EntitlementOverride $override) use ($record): array {
                $snapshot = $override->snapshot();

                return [
                    'tenant_id' => $record->tenantId,
                    'namespace' => $override->entitlementNamespace(),
                    'key' => $override->key(),
                    'value' => $override->value(),
                    'reason' => $snapshot['reason'],
                    'actor' => $snapshot['actor'],
                    'created_at' => self::formatExact($override->createdAt()),
                ];
            },
            $change->overrides(),
        );
        $record->addOns = new ArrayCollection($change->addOns());

        return $record;
    }

    public static function fromManualAdjustment(
        SubscriptionChange $change,
        string $requestedBy,
        string $reason,
    ): self {
        $requestedBy = trim($requestedBy);
        $reason = trim($reason);
        if (
            strlen($requestedBy) !== 26
            || $reason === ''
            || mb_strlen($reason, 'UTF-8') > 500
        ) {
            throw new DomainException('Auditoría de ajuste comercial inválida.');
        }

        $record = self::fromChange($change);
        $record->requestedBy = $requestedBy;
        $record->auditReason = $reason;

        return $record;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function direction(): string
    {
        return $this->direction;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function requestedAt(): DateTimeImmutable
    {
        return $this->parseExact($this->requestedAt);
    }

    public function effectiveAt(): ?DateTimeImmutable
    {
        return $this->effectiveAt === null ? null : $this->parseExact($this->effectiveAt);
    }

    /** @return array{requested_by:string,reason:string}|null */
    public function audit(): ?array
    {
        if ($this->requestedBy === null && $this->auditReason === null) {
            return null;
        }
        if (
            $this->requestedBy === null
            || strlen($this->requestedBy) !== 26
            || $this->auditReason === null
            || trim($this->auditReason) === ''
            || mb_strlen($this->auditReason, 'UTF-8') > 500
        ) {
            throw new DomainException('Auditoría persistida de ajuste comercial inválida.');
        }

        return [
            'requested_by' => $this->requestedBy,
            'reason' => $this->auditReason,
        ];
    }

    public function toChange(): SubscriptionChange
    {
        if (trim($this->tenantId) === '') {
            throw new DomainException('Tenant persistido inválido.');
        }

        $requestedAt = $this->requestedAt();
        $effectiveAt = $this->effectiveAt();
        $addOns = array_values($this->addOns->toArray());
        usort(
            $addOns,
            static fn (AddOn $left, AddOn $right): int => $left->key() <=> $right->key(),
        );
        $overrides = $this->restoreOverrides();
        $blockers = $this->restoreBlockers();

        if ($this->direction === 'upgrade') {
            if (
                $this->status !== 'effective'
                || !$effectiveAt instanceof \DateTimeImmutable
                || self::formatExact($effectiveAt) !== self::formatExact($requestedAt)
                || $blockers !== []
            ) {
                throw new DomainException('Upgrade persistido incoherente.');
            }

            return SubscriptionChange::upgrade(
                $this->tenantId,
                $this->currentPlanVersion,
                $this->targetPlanVersion,
                $requestedAt,
                $addOns,
                $overrides,
            );
        }

        if ($this->direction !== 'downgrade') {
            throw new DomainException('Dirección persistida de cambio inválida.');
        }

        if ($this->status === 'scheduled') {
            if (!$effectiveAt instanceof \DateTimeImmutable || $blockers !== []) {
                throw new DomainException('Downgrade programado persistido incoherente.');
            }

            return SubscriptionChange::downgrade(
                $this->tenantId,
                $this->currentPlanVersion,
                $this->targetPlanVersion,
                $requestedAt,
                $effectiveAt,
                true,
                $addOns,
                $overrides,
            );
        }

        if ($this->status === 'pending_resolution') {
            if ($effectiveAt instanceof \DateTimeImmutable || $blockers === []) {
                throw new DomainException('Downgrade bloqueado persistido incoherente.');
            }

            return SubscriptionChange::downgrade(
                $this->tenantId,
                $this->currentPlanVersion,
                $this->targetPlanVersion,
                $requestedAt,
                $requestedAt->add(new DateInterval('PT1S')),
                false,
                $addOns,
                $overrides,
                $blockers,
            );
        }

        throw new DomainException('Estado persistido de cambio inválido.');
    }

    /** @return list<EntitlementOverride> */
    private function restoreOverrides(): array
    {
        if (!array_is_list($this->overrideSnapshots) || count($this->overrideSnapshots) > 50) {
            throw new DomainException('Overrides persistidos inválidos.');
        }

        $overrides = [];
        foreach ($this->overrideSnapshots as $snapshot) {
            if (!is_array($snapshot) || array_is_list($snapshot)) {
                throw new DomainException('Override persistido inválido.');
            }

            $keys = array_keys($snapshot);
            sort($keys, SORT_STRING);
            if ($keys !== [
                'actor',
                'created_at',
                'key',
                'namespace',
                'reason',
                'tenant_id',
                'value',
            ]) {
                throw new DomainException('Shape persistido de override inválido.');
            }

            $namespace = $snapshot['namespace'];
            $key = $snapshot['key'];
            $value = $snapshot['value'];
            $reason = $snapshot['reason'];
            $actor = $snapshot['actor'];
            $createdAt = $snapshot['created_at'];

            $snapshotTenantId = $snapshot['tenant_id'] ?? null;

            if (
                !is_string($snapshotTenantId)
                || $snapshotTenantId !== $this->tenantId
                || !is_string($namespace)
                || !is_string($key)
                || (!is_bool($value) && !is_int($value) && !is_string($value) && $value !== null)
                || !is_string($reason)
                || !is_string($actor)
                || !is_string($createdAt)
            ) {
                throw new DomainException('Override persistido incompatible con tenant.');
            }

            $override = new EntitlementOverride(
                $this->tenantId,
                $namespace,
                $key,
                $value,
                $reason,
                $actor,
                $this->parseExact($createdAt),
            );
            $overrideIdentity = $override->entitlementNamespace().':'.$override->key();
            if (isset($overrides[$overrideIdentity])) {
                throw new DomainException('Overrides persistidos duplicados.');
            }
            $overrides[$overrideIdentity] = $override;
        }

        ksort($overrides, SORT_STRING);

        return array_values($overrides);
    }

    /** @return list<string> */
    private function restoreBlockers(): array
    {
        if (!array_is_list($this->blockers) || count($this->blockers) > 10) {
            throw new DomainException('Blockers persistidos inválidos.');
        }

        $blockers = [];
        foreach ($this->blockers as $blocker) {
            if (!is_string($blocker)) {
                throw new DomainException('Blocker persistido inválido.');
            }
            if (isset($blockers[$blocker])) {
                throw new DomainException('Blockers persistidos duplicados.');
            }
            $blockers[$blocker] = $blocker;
        }
        ksort($blockers, SORT_STRING);

        return array_values($blockers);
    }

    private static function formatExact(DateTimeImmutable $value): string
    {
        return $value
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\\TH:i:s.u\\Z');
    }

    private function parseExact(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d\\TH:i:s.u\\Z',
            $value,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || self::formatExact($parsed) !== $value
        ) {
            throw new DomainException('Timestamp persistido de cambio inválido.');
        }

        return $parsed;
    }
}
