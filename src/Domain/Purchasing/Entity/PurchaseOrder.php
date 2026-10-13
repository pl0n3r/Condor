<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Entity;

use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\Material;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_purchasing_order')]
#[ORM\UniqueConstraint(name: 'uniq_purchasing_order_tenant_id', columns: ['tenant_id', 'id'])]
#[ORM\UniqueConstraint(name: 'uniq_purchasing_order_tenant_receipt', columns: ['tenant_id', 'receipt_key'])]
class PurchaseOrder
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_RECEIVED = 'received';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'supplier_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Supplier $supplier;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(name: 'receipt_key', type: 'string', length: 80, nullable: true)]
    private ?string $receiptKey = null;

    #[ORM\Column(name: 'receipt_source_id', type: 'string', length: 26, nullable: true)]
    private ?string $receiptSourceId = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'received_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $receivedAt = null;

    /** @var Collection<int, PurchaseOrderLine> */
    #[ORM\OneToMany(mappedBy: 'purchaseOrder', targetEntity: PurchaseOrderLine::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $lines;

    public function __construct(Tenant $tenant, Supplier $supplier)
    {
        if ($supplier->tenant()->id() !== $tenant->id() || !$supplier->isActive()) {
            throw new DomainException('La orden requiere un proveedor activo del mismo tenant.');
        }
        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->supplier = $supplier;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->lines = new ArrayCollection();
    }

    public function id(): string { return $this->id; }
    public function tenant(): Tenant { return $this->tenant; }
    public function supplier(): Supplier { return $this->supplier; }
    public function status(): string { return $this->status; }
    public function receiptKey(): ?string { return $this->receiptKey; }
    public function receiptSourceId(): ?string { return $this->receiptSourceId; }
    public function isReceived(): bool { return $this->status === self::STATUS_RECEIVED; }

    /** @return list<PurchaseOrderLine> */
    public function lines(): array { return array_values($this->lines->toArray()); }

    public function addLine(Material $material, string $quantity, string $unitCost): PurchaseOrderLine
    {
        if ($this->isReceived()) {
            throw new DomainException('Una orden recibida no puede modificarse.');
        }
        if ($material->tenant()->id() !== $this->tenant->id() || !$material->isActive()) {
            throw new DomainException('El material debe estar activo y pertenecer al tenant de la orden.');
        }
        foreach ($this->lines as $line) {
            if ($line->material()->id() === $material->id()) {
                throw new DomainException('No se permite repetir un material en la misma orden.');
            }
        }
        $line = new PurchaseOrderLine($this, $material, $quantity, $unitCost);
        $this->lines->add($line);

        return $line;
    }

    public static function normalizeReceiptKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 80 || preg_match('/^[A-Za-z0-9._:-]+$/D', $key) !== 1) {
            throw new DomainException('La clave de recepción debe ser canónica y tener máximo 80 caracteres.');
        }
        return $key;
    }

    public function assertSameReceipt(string $key, string $sourceId): void
    {
        if (!$this->isReceived()
            || $this->receiptKey !== self::normalizeReceiptKey($key)
            || $this->receiptSourceId !== $sourceId
        ) {
            throw new DomainException('La orden ya fue recibida con otra clave o fuente.');
        }
    }

    public function markReceived(string $key, string $sourceId): void
    {
        $key = self::normalizeReceiptKey($key);
        if ($this->isReceived()) {
            $this->assertSameReceipt($key, $sourceId);
            return;
        }
        if (strlen($sourceId) !== 26 || $this->lines->isEmpty()) {
            throw new DomainException('La recepción requiere una fuente válida y líneas de materiales.');
        }
        $this->status = self::STATUS_RECEIVED;
        $this->receiptKey = $key;
        $this->receiptSourceId = $sourceId;
        $this->receivedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
