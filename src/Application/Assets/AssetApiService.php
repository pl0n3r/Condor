<?php

declare(strict_types=1);

namespace App\Application\Assets;

use App\Domain\Assets\Entity\AssetRecord;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use App\Infrastructure\Persistence\AssetRecordRepository;
use DomainException;

/** Construction-only facade: synthetic records, server-derived tenant + company scope. */
final readonly class AssetApiService
{
    private const ACTOR = 'fixture:actor/api';

    public function __construct(private AssetRecordRepository $records)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listRecords(Tenant $tenant, Branch $office): array
    {
        return $this->records->forTenant(self::scope($tenant, $office), self::ACTOR, true);
    }

    /** @return array<string,mixed>|null */
    public function viewRecord(Tenant $tenant, Branch $office, string $slug): ?array
    {
        return $this->records->view(self::scope($tenant, $office), self::assetRef($slug), self::ACTOR, true);
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,string|null>
     */
    public function createRecord(Tenant $tenant, Branch $office, array $payload): array
    {
        self::allowFields($payload, ['asset_ref', 'warranty_until']);
        $ref = $payload['asset_ref'] ?? null;
        if (!is_string($ref)) {
            throw new DomainException('Referencia de activo sintético obligatoria.');
        }
        AssetRecord::assertAssetRef($ref);
        $warranty = $payload['warranty_until'] ?? null;
        if ($warranty !== null && !is_string($warranty)) {
            throw new DomainException('Garantía sintética inválida.');
        }
        $key = self::scope($tenant, $office);
        return $this->records->create(
            new AssetRecord($key, $ref, self::ACTOR, true, $warranty),
            $key,
            self::ACTOR,
            true,
        );
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    public function changeState(Tenant $tenant, Branch $office, string $slug, array $payload): array
    {
        self::allowFields($payload, ['state', 'evidence_ref']);
        $next = $payload['state'] ?? null;
        if (!is_string($next)) {
            throw new DomainException('Estado de revisión sintético obligatorio.');
        }
        $evidence = $payload['evidence_ref'] ?? null;
        if ($evidence !== null && !is_string($evidence)) {
            throw new DomainException('Evidencia sintética inválida.');
        }
        return $this->records->transition(
            self::scope($tenant, $office), self::assetRef($slug), self::ACTOR, true, $next, $evidence,
        );
    }

    /** Derive a pseudonymous fixture key from authenticated tenant and company, never HTTP input. */
    private static function scope(Tenant $tenant, Branch $office): string
    {
        $company = $office->legalEntity();
        if ($office->tenant()->id() !== $tenant->id() || $company === null
            || $company->tenant()->id() !== $tenant->id()) {
            throw new DomainException('Empresa sintética fuera del ámbito autorizado.');
        }
        return 'tenant-test-c'.substr(hash('sha256', $tenant->id().'|'.$company->id()), 0, 24);
    }

    private static function assetRef(string $slug): string
    {
        $ref = 'fixture:asset/'.$slug;
        AssetRecord::assertAssetRef($ref);
        return $ref;
    }

    /** @param array<string,mixed> $data
     *  @param list<string> $allowed
     */
    private static function allowFields(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new DomainException('El cuerpo contiene campos no permitidos.');
        }
    }
}
