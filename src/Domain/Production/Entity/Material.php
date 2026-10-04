<?php

declare(strict_types=1);

namespace App\Domain\Production\Entity;

use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_production_material')]
#[ORM\UniqueConstraint(
    name: 'uniq_production_material_organization_code',
    columns: ['organization_id', 'code'],
)]
#[ORM\UniqueConstraint(
    name: 'uniq_production_material_organization_id',
    columns: ['organization_id', 'id'],
)]
class Material
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(
        name: 'organization_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE',
    )]
    private Tenant $organization;

    #[ORM\Column(type: 'string', length: 64)]
    private string $code;

    #[ORM\Column(type: 'string', length: 160)]
    private string $name;

    #[ORM\Column(name: 'unit_of_measure', type: 'string', length: 16)]
    private string $unitOfMeasure;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        Tenant $organization,
        string $code,
        string $name,
        UnitOfMeasure $unitOfMeasure,
    ) {
        $this->id = UlidFactory::new();
        $this->organization = $organization;
        $this->code = self::canonicalCode($code);
        $this->name = self::canonicalName($name);
        $this->unitOfMeasure = $unitOfMeasure->key();
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenant(): Tenant
    {
        return $this->organization;
    }

    public function organizationId(): string
    {
        return $this->organization->id();
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function unitOfMeasure(): UnitOfMeasure
    {
        return UnitOfMeasure::from($this->unitOfMeasure);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function update(
        string $code,
        string $name,
        UnitOfMeasure $unitOfMeasure,
    ): void {
        $this->code = self::canonicalCode($code);
        $this->name = self::canonicalName($name);
        $this->unitOfMeasure = $unitOfMeasure->key();
        $this->touch();
    }

    public function deactivate(): void
    {
        if (!$this->active) {
            return;
        }

        $this->active = false;
        $this->touch();
    }

    public static function canonicalCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if (
            $code === ''
            || mb_strlen($code, 'UTF-8') > 64
            || preg_match('/^[A-Z0-9][A-Z0-9._-]*$/D', $code) !== 1
        ) {
            throw new DomainException(
                'El código del material admite 1–64 caracteres alfanuméricos, punto, guion o guion bajo.',
            );
        }

        return $code;
    }

    private static function canonicalName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
            throw new DomainException(
                'El nombre del material es obligatorio y admite máximo 160 caracteres.',
            );
        }

        return $name;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
