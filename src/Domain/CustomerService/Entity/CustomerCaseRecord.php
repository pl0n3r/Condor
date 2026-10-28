<?php

declare(strict_types=1);

namespace App\Domain\CustomerService\Entity;

use App\Domain\Organization\Entity\Tenant;
use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

/** Records opaque synthetic case identifiers, never real customer details. */
#[ORM\Entity]
#[ORM\Table(name: 'condor_customer_case_record')]
#[ORM\UniqueConstraint(name: 'uniq_ccr_tenant_reference', columns: ['tenant_id', 'case_reference'])]
#[ORM\UniqueConstraint(name: 'uniq_ccr_tenant_id', columns: ['tenant_id', 'id'])]
final class CustomerCaseRecord
{
    public const STATUS_PENDING = 'PENDIENTE';
    public const STATUS_REVIEW = 'EN_REVISION';
    public const STATUS_CLOSED = 'CERRADO';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Tenant $tenant;

    #[ORM\Column(name: 'case_reference', type: 'string', length: 96)]
    private string $caseReference;

    #[ORM\Column(name: 'case_kind', type: 'string', length: 20)]
    private string $kind;

    #[ORM\Column(name: 'status', type: 'string', length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'version', type: 'integer')]
    private int $version = 1;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(Tenant $tenant, string $caseReference, string $kind)
    {
        self::assertFixtureReference($caseReference);
        if (!in_array($kind, ['PQRS', 'GARANTIA', 'SOPORTE'], true)) {
            throw new DomainException('Clase de caso sintético desconocida.');
        }
        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->caseReference = $caseReference;
        $this->kind = $kind;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function id(): string { return $this->id; }
    public function tenant(): Tenant { return $this->tenant; }
    public function caseReference(): string { return $this->caseReference; }
    public function kind(): string { return $this->kind; }
    public function status(): string { return $this->status; }
    public function version(): int { return $this->version; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    public static function assertFixtureReference(string $reference): void
    {
        if (preg_match('/\Afixture:customer-case\/[a-z][a-z0-9_-]{0,63}\z/D', $reference) !== 1) {
            throw new DomainException('Solo referencias opacas sintéticas de caso.');
        }
    }
}
