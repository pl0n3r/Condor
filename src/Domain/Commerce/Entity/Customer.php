<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Entity;

use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'condor_customer')]
#[ORM\UniqueConstraint(
    name: 'uniq_customer_tenant_id',
    columns: ['tenant_id', 'id'],
)]
class Customer extends CommercialItem
{
    #[ORM\ManyToOne(targetEntity: CommercialCategory::class)]
    #[ORM\JoinColumn(
        name: 'commercial_category_id',
        referencedColumnName: 'id',
        nullable: true,
        onDelete: 'RESTRICT',
    )]
    private ?CommercialCategory $commercialCategory;

    #[ORM\Column(type: 'string', length: 180)]
    private string $name;

    #[ORM\Column(type: 'string', length: 254, nullable: true)]
    private ?string $email;

    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $phone;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    public function __construct(
        Tenant $tenant,
        string $name,
        ?string $email = null,
        ?string $phone = null,
        ?string $notes = null,
        ?CommercialCategory $commercialCategory = null,
    ) {
        parent::__construct($tenant);
        $this->name = self::normalizeName($name);
        $this->email = self::normalizeEmail($email);
        $this->phone = self::normalizePhone($phone);
        $this->notes = self::normalizeNotes($notes);
        $this->commercialCategory = null;
        $this->assignCommercialCategory($commercialCategory);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): ?string
    {
        return $this->email;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function commercialCategory(): ?CommercialCategory
    {
        return $this->commercialCategory;
    }

    public function update(
        string $name,
        ?string $email,
        ?string $phone,
        ?string $notes,
    ): void {
        $this->name = self::normalizeName($name);
        $this->email = self::normalizeEmail($email);
        $this->phone = self::normalizePhone($phone);
        $this->notes = self::normalizeNotes($notes);
        $this->touch();
    }

    public function assignCommercialCategory(
        ?CommercialCategory $category,
    ): void {
        if (
            $category !== null
            && $category->tenant()->id() !== $this->tenant()->id()
        ) {
            throw new DomainException(
                'La categoría comercial debe pertenecer al mismo tenant '
                .'del cliente.',
            );
        }

        $this->commercialCategory = $category;
        $this->touch();
    }

    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 180) {
            throw new DomainException(
                'El nombre del cliente es obligatorio '
                .'y admite máximo 180 caracteres.',
            );
        }

        return $name;
    }

    private static function normalizeEmail(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        $email = mb_strtolower(trim($email), 'UTF-8');
        if (
            mb_strlen($email, 'UTF-8') > 254
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new DomainException(
                'El correo del cliente no es válido.',
            );
        }

        return $email;
    }

    private static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $phone = trim($phone);
        if (mb_strlen($phone, 'UTF-8') > 40) {
            throw new DomainException(
                'El teléfono del cliente admite máximo 40 caracteres.',
            );
        }

        return $phone;
    }

    private static function normalizeNotes(?string $notes): ?string
    {
        if ($notes === null || trim($notes) === '') {
            return null;
        }

        $notes = trim($notes);
        if (mb_strlen($notes, 'UTF-8') > 5000) {
            throw new DomainException(
                'Las observaciones del cliente admiten máximo 5000 caracteres.',
            );
        }

        return $notes;
    }
}
