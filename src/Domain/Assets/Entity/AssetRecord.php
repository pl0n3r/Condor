<?php

declare(strict_types=1);

namespace App\Domain\Assets\Entity;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

/** Synthetic-only M10 asset state, segregated by tenant and asset reference. */
#[ORM\Entity]
#[ORM\Table(name: 'condor_asset_record')]
final class AssetRecord
{
    public const PENDING = 'PENDIENTE';
    public const REVIEW = 'EN_REVISION';
    public const DECOMMISSION_REVIEW = 'BAJA_REQUIERE_REVISION';

    #[ORM\Id]
    #[ORM\Column(name: 'tenant_ref', type: 'string', length: 80)]
    private string $tenantRef;

    #[ORM\Id]
    #[ORM\Column(name: 'asset_ref', type: 'string', length: 80)]
    private string $assetRef;

    #[ORM\Column(name: 'actor_ref', type: 'string', length: 80)]
    private string $actorRef;

    #[ORM\Column(name: 'state', type: 'string', length: 32)]
    private string $state = self::PENDING;

    #[ORM\Column(name: 'responsible_ref', type: 'string', length: 80, nullable: true)]
    private ?string $responsibleRef = null;

    #[ORM\Column(name: 'evidence_ref', type: 'string', length: 80, nullable: true)]
    private ?string $evidenceRef = null;

    #[ORM\Column(name: 'warranty_until', type: 'string', length: 10, nullable: true)]
    private ?string $warrantyUntil = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $tenantRef,
        string $assetRef,
        string $actorRef,
        bool $authorized,
        ?string $warrantyUntil = null,
    ) {
        self::assertAuthority($tenantRef, $actorRef, $authorized);
        self::assertAssetRef($assetRef);
        if ($warrantyUntil !== null && !self::date($warrantyUntil)) {
            throw new DomainException('Garantía sintética inválida.');
        }
        $this->tenantRef = $tenantRef;
        $this->assetRef = $assetRef;
        $this->actorRef = $actorRef;
        $this->warrantyUntil = $warrantyUntil;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public static function assertAuthority(string $tenantRef, string $actorRef, bool $authorized): void
    {
        if (!$authorized
            || preg_match('/^tenant-(?:test-)?[a-z][a-z0-9-]{0,31}$/D', $tenantRef) !== 1
            || preg_match('~^fixture:actor/[a-z][a-z0-9_-]{0,47}$~D', $actorRef) !== 1) {
            throw new DomainException('Acceso no autorizado o referencias no sintéticas.');
        }
    }

    public static function assertAssetRef(string $assetRef): void
    {
        if (preg_match('~^fixture:asset/[a-z][a-z0-9_-]{0,47}$~D', $assetRef) !== 1) {
            throw new DomainException('Referencia de activo no sintética.');
        }
    }

    public function assignResponsible(string $responsibleRef, string $actorRef, bool $authorized): void
    {
        self::assertAuthority($this->tenantRef, $actorRef, $authorized);
        if (preg_match('~^fixture:responsible/[a-z][a-z0-9_-]{0,47}$~D', $responsibleRef) !== 1) {
            throw new DomainException('Responsable sintético obligatorio.');
        }
        $this->responsibleRef = $responsibleRef;
        $this->state = 'ASIGNADO';
        $this->actorRef = $actorRef;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function transition(string $state, string $actorRef, bool $authorized, ?string $evidenceRef = null): void
    {
        self::assertAuthority($this->tenantRef, $actorRef, $authorized);
        if (!in_array($state, [self::REVIEW, self::DECOMMISSION_REVIEW], true)
            || ($state === self::DECOMMISSION_REVIEW
                && ($evidenceRef === null
                    || preg_match('~^fixture:evidence/[a-z][a-z0-9_-]{0,47}$~D', $evidenceRef) !== 1))
            || ($evidenceRef !== null
                && preg_match('~^fixture:evidence/[a-z][a-z0-9_-]{0,47}$~D', $evidenceRef) !== 1)) {
            throw new DomainException('El cierre automático está prohibido; transición o evidencia inválida.');
        }
        $this->state = $state;
        $this->actorRef = $actorRef;
        $this->evidenceRef = $evidenceRef;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function tenantRef(): string { return $this->tenantRef; }
    public function assetRef(): string { return $this->assetRef; }
    public function state(): string { return $this->state; }
    public function actorRef(): string { return $this->actorRef; }

    /** @return array<string, string|null> */
    public function snapshot(): array
    {
        return [
            'tenant_ref' => $this->tenantRef,
            'asset_ref' => $this->assetRef,
            'actor_ref' => $this->actorRef,
            'state' => $this->state,
            'responsible_ref' => $this->responsibleRef,
            'evidence_ref' => $this->evidenceRef,
            'warranty_until' => $this->warrantyUntil,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }


    /** Real-table identifiers are validated independently from the legacy fixture-only ORM. */
    public static function assertRealIdentity(string $tenant, string $company, string $actor): void
    {
        foreach ([$tenant, $company, $actor] as $id) {
            if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $id) !== 1) {
                throw new DomainException('Identidad de ámbito o actor inválida.');
            }
        }
    }

    public static function assertRealReference(string $ref): void
    {
        if (strlen($ref) < 1 || strlen($ref) > 64
            || preg_match('/^[a-z][a-z0-9_-]*$/D', $ref) !== 1
            || preg_match('/[0-9]{7,}/', $ref) === 1) {
            throw new DomainException('Referencia de activo debe ser opaca, no personal.');
        }
    }

    /** @return array<string,mixed> */
    public static function beginReal(
        string $tenant, string $company, string $actor, string $ref,
        string $category, ?string $warrantyUntil = null,
    ): array {
        self::assertRealIdentity($tenant, $company, $actor);
        self::assertRealReference($ref);
        if (!in_array($category, ['EQUIPO', 'MUEBLE', 'VEHICULO'], true)
            || ($warrantyUntil !== null && !self::date($warrantyUntil))) {
            throw new DomainException('Categoría o fecha de garantía inválida.');
        }
        return [
            'id' => \App\Shared\Id\UlidFactory::new(),
            'tenant_id' => $tenant,
            'company_id' => $company,
            'record_ref' => $ref,
            'category' => $category,
            'state' => self::PENDING,
            'responsible_ref' => null,
            'evidence_ref' => null,
            'warranty_until' => $warrantyUntil,
            'version' => 1,
            'previous_id' => null,
            'actor_id' => $actor,
            'occurred_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    public static function inspectReal(array $row, string $tenant, string $company): array
    {
        if (($row['tenant_id'] ?? null) !== $tenant || ($row['company_id'] ?? null) !== $company) {
            throw new DomainException('Activo pertenece a otra empresa o razón social.');
        }
        $actor = $row['actor_id'] ?? null;
        if (!is_string($actor)) {
            throw new DomainException('Auditoría de activo inválida.');
        }
        self::assertRealIdentity($tenant, $company, $actor);
        self::assertRealReference((string) ($row['record_ref'] ?? ''));
        foreach (['id', 'previous_id'] as $field) {
            $id = $row[$field] ?? null;
            if (($field === 'id' || $id !== null)
                && (!is_string($id) || preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $id) !== 1)) {
                throw new DomainException('Identidad de revisión de activo inválida.');
            }
        }
        $version = $row['version'] ?? null;
        $number = filter_var($version, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000000],
        ]);
        if (!is_int($number) || (string) $number !== (string) $version
            || !in_array($row['category'] ?? null, ['EQUIPO', 'MUEBLE', 'VEHICULO'], true)
            || !in_array($row['state'] ?? null, [
                self::PENDING, 'ASIGNADO', self::REVIEW, self::DECOMMISSION_REVIEW,
            ], true)) {
            throw new DomainException('Versión, categoría o estado de activo inválido.');
        }
        $row['version'] = $number;
        return $row;
    }

    /** @param array<string,mixed> $old
     *  @return array<string,mixed>
     */
    public static function reviseReal(
        array $old, string $tenant, string $company, string $actor, string $next,
        int $expectedVersion, ?string $responsible = null, ?string $evidence = null,
    ): array {
        self::assertRealIdentity($tenant, $company, $actor);
        $row = self::inspectReal($old, $tenant, $company);
        $allowed = [
            self::PENDING => ['ASIGNADO', self::REVIEW],
            'ASIGNADO' => [self::REVIEW, self::DECOMMISSION_REVIEW],
            self::REVIEW => ['ASIGNADO', self::DECOMMISSION_REVIEW],
            self::DECOMMISSION_REVIEW => [self::REVIEW],
        ];
        if ($expectedVersion !== $row['version'] || $expectedVersion >= 1000000
            || !in_array($next, $allowed[$row['state']], true)) {
            throw new DomainException('Transición de activo no autorizada o versión obsoleta.');
        }
        if ($responsible !== null) {
            self::assertRealReference($responsible);
        }
        if ($evidence !== null) {
            self::assertRealReference($evidence);
        }
        if (($next === 'ASIGNADO' && $responsible === null)
            || ($next === self::DECOMMISSION_REVIEW && $evidence === null)
            || ($next !== 'ASIGNADO' && $responsible !== null)
            || ($next !== self::DECOMMISSION_REVIEW && $evidence !== null)) {
            throw new DomainException('Responsable o evidencia requerida para la transición.');
        }
        $row['previous_id'] = $row['id'];
        $row['id'] = \App\Shared\Id\UlidFactory::new();
        $row['version']++;
        $row['state'] = $next;
        $row['responsible_ref'] = $responsible ?? $row['responsible_ref'];
        $row['evidence_ref'] = $evidence ?? $row['evidence_ref'];
        $row['actor_id'] = $actor;
        $row['occurred_at'] = gmdate('Y-m-d H:i:s');
        return $row;
    }

    private static function date(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }
}
