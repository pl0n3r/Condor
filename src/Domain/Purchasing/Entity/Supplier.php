<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Entity;

use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_purchasing_supplier')]
#[ORM\UniqueConstraint(name: 'uniq_purchasing_supplier_tenant_code', columns: ['tenant_id', 'code'])]
#[ORM\UniqueConstraint(name: 'uniq_purchasing_supplier_tenant_id', columns: ['tenant_id', 'id'])]
class Supplier
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(type: 'string', length: 64)]
    private string $code;

    #[ORM\Column(type: 'string', length: 160)]
    private string $name;

    #[ORM\Column(name: 'payment_terms_days', type: 'smallint', options: ['unsigned' => true, 'default' => 0])]
    private int $paymentTermsDays;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(Tenant $tenant, string $code, string $name, int $paymentTermsDays = 0)
    {
        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->code = self::canonicalCode($code);
        $this->name = self::canonicalName($name);
        $this->paymentTermsDays = self::validPaymentTerms($paymentTermsDays);
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function paymentTermsDays(): int
    {
        return $this->paymentTermsDays;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function update(string $code, string $name, int $paymentTermsDays): void
    {
        // Check every field before mutating any field.
        $canonicalCode = self::canonicalCode($code);
        $canonicalName = self::canonicalName($name);
        $terms = self::validPaymentTerms($paymentTermsDays);
        $this->code = $canonicalCode;
        $this->name = $canonicalName;
        $this->paymentTermsDays = $terms;
        $this->touch();
    }

    public function deactivate(): void
    {
        if ($this->active) {
            $this->active = false;
            $this->touch();
        }
    }

    public static function canonicalCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if ($code === '' || strlen($code) > 64 || preg_match('/^[A-Z0-9][A-Z0-9._-]*$/D', $code) !== 1) {
            throw new DomainException('El código del proveedor debe ser alfanumérico (máximo 64 caracteres).');
        }

        return $code;
    }

    private static function canonicalName(string $name): string
    {
        $name = trim($name);
        // Rechazar entradas invisibles y normalizar espacios Unicode en los bordes.
        $name = preg_replace('/\A[\p{Z}\p{Cf}\s]+|[\p{Z}\p{Cf}\s]+\z/u', '', $name);
        // Rechazar controles y formatos invisibles internos (incluidas marcas bidi),
        // excepto ZWNJ/ZWJ legítimos para escritura Unicode y emoji compuestos.
        $unsafe = $name !== null && preg_match('/[\p{Cc}\p{Zl}\p{Zp}]|(?![\x{200C}\x{200D}])\p{Cf}/u', $name) === 1;
        // Los caracteres de control o marcas Unicode solas no son nombres visibles.
        $visible = $name !== null && preg_match('/[\p{L}\p{N}\p{P}\p{S}]/u', $name) === 1;
        // ext-iconv es obligatoria en Composer; mbstring no lo es.
        $length = $name === null ? false : iconv_strlen($name, 'UTF-8');
        if (!$visible || $unsafe || $length === false || $length < 1 || $length > 160) {
            throw new DomainException('El nombre del proveedor es obligatorio (máximo 160 caracteres).');
        }

        return $name;
    }

    private static function validPaymentTerms(int $days): int
    {
        if ($days < 0 || $days > 365) {
            throw new DomainException('El plazo de pago debe estar entre 0 y 365 días.');
        }

        return $days;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
