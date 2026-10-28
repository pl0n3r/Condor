<?php

declare(strict_types=1);

namespace App\Domain\HumanResources\Entity;

use App\Shared\Id\UlidFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

/** M01: un evento de personal estrictamente ficticio, sin flujo operativo ni PII. */
#[ORM\Entity]
#[ORM\Table(name: 'condor_employee_record')]
#[ORM\UniqueConstraint(name: 'uniq_employee_scope_version', columns: ['tenant_ref', 'company_ref', 'employee_ref', 'version'])]
#[ORM\Index(name: 'idx_employee_company_state', columns: ['tenant_ref', 'company_ref', 'state'])]
final class EmployeeRecord
{
    public const PENDING = 'PENDIENTE';
    public const REVIEW = 'REQUIERE_REVISION';

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'tenant_ref', length: 80)]
    private string $tenant;

    #[ORM\Column(name: 'company_ref', length: 80)]
    private string $company;

    #[ORM\Column(name: 'employee_ref', length: 80)]
    private string $employee;

    #[ORM\Column(name: 'actor_ref', length: 80)]
    private string $actor;

    #[ORM\Column(length: 20)]
    private string $category;

    #[ORM\Column(length: 20)]
    private string $state;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $version;

    #[ORM\Column(name: 'previous_id', length: 26, nullable: true)]
    private ?string $previousId;

    #[ORM\Column(name: 'previous_state', length: 20, nullable: true)]
    private ?string $previousState;

    #[ORM\Column(name: 'audit_action', length: 20)]
    private string $action;

    #[ORM\Column(name: 'occurred_at', type: 'datetime_immutable')]
    private DateTimeImmutable $timestamp;

    #[ORM\Column(length: 64)]
    private string $fingerprint;

    private function __construct(
        string $tenant,
        string $company,
        string $employee,
        string $category,
        string $actor,
        string $state,
        ?self $previous
    ) {
        self::assertScope($tenant, $company, $actor);
        self::assertFixture($employee, 'employee');
        if (!in_array($category, ['vinculacion', 'novedad', 'vacacion', 'evaluacion'], true)) {
            throw new DomainException('Categoría de talento humano no admitida.');
        }
        if (!in_array($state, [self::PENDING, self::REVIEW], true)) {
            throw new DomainException('Un registro sintético no puede cerrarse automáticamente.');
        }
        if ($previous !== null && $previous->version === 2147483647) {
            throw new DomainException('El historial de talento humano agotó su versión.');
        }

        $this->id = UlidFactory::new();
        $this->tenant = $tenant;
        $this->company = $company;
        $this->employee = $employee;
        $this->actor = $actor;
        $this->category = $category;
        $this->state = $state;
        $this->version = $previous === null ? 1 : $previous->version + 1;
        $this->previousId = $previous?->id;
        $this->previousState = $previous?->state;
        $this->action = $previous === null ? 'CREADA' : 'CAMBIO_EXPLICITO';
        $this->timestamp = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->fingerprint = self::fingerprintFromRow($this->attributes());
    }

    public static function open(
        string $tenantRef, string $companyRef, string $employeeRef,
        string $category, string $actorRef
    ): self {
        return new self($tenantRef, $companyRef, $employeeRef, $category, $actorRef, self::PENDING, null);
    }

    public function transition(string $state, string $actorRef, bool $humanApproved): self
    {
        if ($humanApproved !== true || $state === $this->state ||
            !in_array($state, [self::PENDING, self::REVIEW], true)) {
            throw new DomainException('La transición M01 exige decisión humana y estado distinto.');
        }

        return new self(
            $this->tenant, $this->company, $this->employee,
            $this->category, $actorRef, $state, $this
        );
    }

    public static function assertFixture(string $value, string $kind): void
    {
        if (!in_array($kind, ['tenant', 'company', 'employee', 'actor'], true) ||
            preg_match('/\Afixture:'.preg_quote($kind, '/').'\/[a-z][a-z0-9_-]{0,47}\z/D', $value) !== 1 ||
            preg_match('/[0-9]{7,}/', $value) === 1) {
            throw new DomainException('M01 requiere únicamente referencias fixture: sin identificadores personales.');
        }
    }

    public static function assertScope(string $tenant, string $company, string $actor): void
    {
        foreach ([['tenant', $tenant], ['company', $company], ['actor', $actor]] as [$kind, $value]) {
            self::assertFixture($value, $kind);
        }
    }

    public function id(): string { return $this->id; }
    public function tenantRef(): string { return $this->tenant; }
    public function companyRef(): string { return $this->company; }
    public function employeeRef(): string { return $this->employee; }
    public function actorRef(): string { return $this->actor; }
    public function state(): string { return $this->state; }
    public function version(): int { return $this->version; }
    public function previousId(): ?string { return $this->previousId; }
    public function previousState(): ?string { return $this->previousState; }

    /** @return array<string, string|int|null> */
    private function attributes(): array
    {
        return [
            'id' => $this->id,
            'tenant_ref' => $this->tenant,
            'company_ref' => $this->company,
            'employee_ref' => $this->employee,
            'category' => $this->category,
            'state' => $this->state,
            'version' => $this->version,
            'previous_id' => $this->previousId,
            'previous_state' => $this->previousState,
            'audit_action' => $this->action,
            'actor_ref' => $this->actor,
            'occurred_at' => $this->timestamp->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string, string|int|null> */
    public function snapshot(): array
    {
        return $this->attributes() + ['fingerprint' => $this->fingerprint];
    }

    /** @param array<string, mixed> $row */
    public static function fingerprintFromRow(array $row): string
    {
        $canonical = [];
        foreach ([
            'id', 'tenant_ref', 'company_ref', 'employee_ref', 'category',
            'state', 'version', 'previous_id', 'previous_state',
            'audit_action', 'actor_ref', 'occurred_at'
        ] as $key) {
            if (!array_key_exists($key, $row)) {
                throw new DomainException('El evento M01 carece de campos auditables.');
            }
            $canonical[$key] = $key === 'version' ? (int) $row[$key] : $row[$key];
        }

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
