<?php

declare(strict_types=1);

namespace App\Domain\Production\Entity;

use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_production_material_movement')]
#[ORM\Index(
    name: 'idx_production_material_movement_scope_time',
    columns: ['tenant_id', 'source_id', 'material_id', 'created_at'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_production_material_movement_tenant_key',
    columns: ['tenant_id', 'idempotency_key'],
)]
class MaterialInventoryMovement
{
    public const TYPE_ADJUSTMENT_IN = 'adjustment_in';
    public const TYPE_ADJUSTMENT_OUT = 'adjustment_out';
    public const TYPE_PRODUCTION_CONSUMPTION = 'production_consumption';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(
        name: 'tenant_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: InventorySource::class)]
    #[ORM\JoinColumn(
        name: 'source_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private InventorySource $source;

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(
        name: 'material_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'RESTRICT',
    )]
    private Material $material;

    #[ORM\Column(type: 'string', length: 30)]
    private string $type;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 6)]
    private string $delta;

    #[ORM\Column(name: 'balance_after', type: 'decimal', precision: 19, scale: 6)]
    private string $balanceAfter;

    #[ORM\Column(name: 'actor_user_id', type: 'string', length: 26, nullable: true)]
    private ?string $actorUserId;

    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 120, nullable: true)]
    private ?string $idempotencyKey;

    /** @var array<string, scalar|null> */
    #[ORM\Column(type: 'json')]
    private array $context;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** @param array<array-key,mixed> $context */
    public function __construct(
        Tenant $tenant,
        InventorySource $source,
        Material $material,
        string $type,
        string $delta,
        string $balanceAfter,
        ?string $actorUserId,
        ?string $idempotencyKey = null,
        array $context = [],
    ) {
        self::assertScope($tenant, $source, $material);
        $delta = MaterialInventoryBalance::normalizeDelta($delta);
        $balanceAfter = MaterialInventoryBalance::normalizeQuantity($balanceAfter);
        self::assertTypeDirection($type, $delta);
        $context = self::normalizeContext($context);

        if ($actorUserId !== null) {
            $actorUserId = trim($actorUserId);
            if ($actorUserId === '' || mb_strlen($actorUserId, 'UTF-8') > 26) {
                throw new DomainException(
                    'El actor de un movimiento de material no es válido.',
                );
            }
        }

        if ($idempotencyKey !== null) {
            $idempotencyKey = self::normalizeIdempotencyKey($idempotencyKey);
        }

        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->source = $source;
        $this->material = $material;
        $this->type = $type;
        $this->delta = $delta;
        $this->balanceAfter = $balanceAfter;
        $this->actorUserId = $actorUserId;
        $this->idempotencyKey = $idempotencyKey;
        $this->context = $context;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function source(): InventorySource
    {
        return $this->source;
    }

    public function material(): Material
    {
        return $this->material;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function delta(): string
    {
        return MaterialInventoryBalance::normalizeDelta($this->delta);
    }

    public function balanceAfter(): string
    {
        return MaterialInventoryBalance::normalizeQuantity($this->balanceAfter);
    }

    public function actorUserId(): ?string
    {
        return $this->actorUserId;
    }

    public function idempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /** @return array<string, scalar|null> */
    public function context(): array
    {
        return $this->context;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public static function normalizeIdempotencyKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || mb_strlen($key, 'UTF-8') > 120) {
            throw new DomainException(
                'La clave de idempotencia del movimiento admite máximo 120 caracteres.',
            );
        }

        return $key;
    }

    /**
     * @param array<array-key,mixed> $context
     * @return array<string, scalar|null>
     */
    private static function normalizeContext(array $context): array
    {
        $normalized = [];
        foreach ($context as $key => $value) {
            if (
                !is_string($key)
                || (!is_scalar($value) && $value !== null)
            ) {
                throw new DomainException(
                    'El contexto del movimiento solo admite claves string y valores scalar/null.',
                );
            }
            $normalized[$key] = $value;
        }
        ksort($normalized);

        return $normalized;
    }

    private static function assertTypeDirection(
        string $type,
        string $delta,
    ): void {
        $known = [
            self::TYPE_ADJUSTMENT_IN,
            self::TYPE_ADJUSTMENT_OUT,
            self::TYPE_PRODUCTION_CONSUMPTION,
        ];
        if (!in_array($type, $known, true)) {
            throw new DomainException(
                'El tipo de movimiento de material no es válido.',
            );
        }

        $negative = str_starts_with($delta, '-');
        if (
            ($type === self::TYPE_ADJUSTMENT_IN && $negative)
            || ($type !== self::TYPE_ADJUSTMENT_IN && !$negative)
        ) {
            throw new DomainException(
                'El signo del movimiento de material no corresponde a su tipo.',
            );
        }
    }

    private static function assertScope(
        Tenant $tenant,
        InventorySource $source,
        Material $material,
    ): void {
        if (
            $source->tenant()->id() !== $tenant->id()
            || $material->tenant()->id() !== $tenant->id()
        ) {
            throw new DomainException(
                'El movimiento, la fuente y el material deben pertenecer al mismo tenant.',
            );
        }
    }
}
