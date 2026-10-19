<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entity;

use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_product_media')]
#[ORM\Index(name: 'idx_product_media_public', columns: ['tenant_id', 'product_id', 'active', 'sort_order'])]
class ProductMedia extends CatalogItem
{
    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'variant_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?ProductVariant $variant;

    #[ORM\Column(name: 'file_path', type: 'string', length: 500)]
    private string $filePath;

    #[ORM\Column(name: 'mime_type', type: 'string', length: 40)]
    private string $mimeType;

    #[ORM\Column(name: 'file_size', type: 'integer')]
    private int $fileSize;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder;

    #[ORM\Column(name: 'is_primary', type: 'boolean')]
    private bool $primary;

    public function __construct(
        Tenant $tenant,
        Product $product,
        ?ProductVariant $variant,
        string $filePath,
        string $mimeType,
        int $fileSize,
        int $sortOrder,
        bool $primary,
    ) {
        if ($product->tenant()->id() !== $tenant->id()
            || ($variant !== null && ($variant->tenant()->id() !== $tenant->id()
                || $variant->product()->id() !== $product->id()))
        ) {
            throw new DomainException('La foto y la variante deben pertenecer al mismo tenant y producto.');
        }
        self::validateFile($tenant, $filePath, $mimeType, $fileSize);
        if ($sortOrder < 0) {
            throw new DomainException('El orden de la foto no puede ser negativo.');
        }
        parent::__construct($tenant);
        $this->product = $product;
        $this->variant = $variant;
        $this->filePath = $filePath;
        $this->mimeType = $mimeType;
        $this->fileSize = $fileSize;
        $this->sortOrder = $sortOrder;
        $this->primary = $primary;
    }

    public function product(): Product { return $this->product; }
    public function variant(): ?ProductVariant { return $this->variant; }
    public function filePath(): string { return $this->filePath; }
    public function mimeType(): string { return $this->mimeType; }
    public function fileSize(): int { return $this->fileSize; }
    public function sortOrder(): int { return $this->sortOrder; }
    public function isPrimary(): bool { return $this->primary; }

    public function setPrimary(bool $primary): void
    {
        if ($this->primary === $primary) return;
        $this->primary = $primary;
        $this->touch();
    }

    public function reorder(int $position): void
    {
        if ($position < 0) throw new DomainException('Posición de foto inválida.');
        if ($this->sortOrder === $position) return;
        $this->sortOrder = $position;
        $this->touch();
    }

    private static function validateFile(Tenant $tenant, string $path, string $mime, int $bytes): void
    {
        $types = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/webp' => ['webp'],
        ];
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $prefix = '/uploads/catalog/'. $tenant->id().'/';
        if ($bytes < 1 || $bytes > 5 * 1024 * 1024
            || !isset($types[$mime]) || !in_array($extension, $types[$mime], true)
            || !str_starts_with($path, $prefix)
            || strlen($path) > 500
            || preg_match('~\A/uploads/catalog/[0-9A-Za-z]{26}/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)\z~D', $path) !== 1
        ) {
            throw new DomainException('La foto requiere ruta local autorizada, formato y tamaño válidos.');
        }
    }
}
