<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Application\Identity\BranchAuthorization;
use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventoryBalance;
use App\Domain\Inventory\Entity\InventoryMovement;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * Tenant-scoped physical-count CSV import. Preview never mutates persisted
 * state; commit delegates every delta to InventoryService's audited lock path.
 */
final readonly class StockCsvAdjuster
{
    private const MAX_BYTES = 65536;
    private const MAX_ROWS = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private BranchAuthorization $authorization,
        private InventoryService $inventory,
    ) {
    }

    /**
     * Pure parse/validation used for offline tests and both HTTP actions.
     *
     * CSV: sku,branch_id,quantity
     * @return array{sha256: string, rows: list<array{line: int, sku: string, branch_id: string, quantity: int}>, errors: list<array{line: int, error: string}>}
     */
    public static function parseCsv(string $csv): array
    {
        if ($csv === '' || strlen($csv) > self::MAX_BYTES
            || str_contains($csv, "\0") || preg_match('//u', $csv) !== 1) {
            throw new DomainException('CSV_INVALID_OR_TOO_LARGE');
        }

        $bytes = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new DomainException('CSV_STREAM_UNAVAILABLE');
        }
        try {
            fwrite($stream, $bytes);
            rewind($stream);
            $header = fgetcsv($stream, 0, ',', '"', '');
            if ($header !== ['sku', 'branch_id', 'quantity']) {
                throw new DomainException('CSV_HEADER_INVALID');
            }

            $rows = [];
            $errors = [];
            $seen = [];
            $line = 1;
            while (($fields = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if ($fields === [null]) {
                    continue;
                }
                ++$line;
                if ($line > self::MAX_ROWS + 1) {
                    throw new DomainException('CSV_ROW_LIMIT');
                }
                if (count($fields) !== 3) {
                    $errors[] = ['line' => $line, 'error' => 'CSV_COLUMNS_INVALID'];
                    continue;
                }
                $sku = strtoupper(trim((string) $fields[0]));
                $branchId = trim((string) $fields[1]);
                $rawQuantity = trim((string) $fields[2]);
                if (preg_match('/^[A-Z0-9][A-Z0-9._-]{0,119}$/D', $sku) !== 1
                    || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $branchId) !== 1
                    || preg_match('/^(0|[1-9][0-9]{0,9})$/D', $rawQuantity) !== 1
                    || (int) $rawQuantity > 2147483647) {
                    $errors[] = ['line' => $line, 'error' => 'CSV_ROW_INVALID'];
                    continue;
                }
                $key = $branchId.'|'.$sku;
                if (isset($seen[$key])) {
                    $errors[] = ['line' => $line, 'error' => 'CSV_DUPLICATE_SKU_BRANCH'];
                    continue;
                }
                $seen[$key] = true;
                $rows[] = [
                    'line' => $line, 'sku' => $sku,
                    'branch_id' => $branchId, 'quantity' => (int) $rawQuantity,
                ];
            }
            if ($rows === [] && $errors === []) {
                throw new DomainException('CSV_EMPTY');
            }
            return [
                'sha256' => hash('sha256', $bytes),
                'rows' => $rows,
                'errors' => $errors,
            ];
        } finally {
            fclose($stream);
        }
    }

    public static function rowKey(string $tenantId, string $sha256, int $line): string
    {
        if ($tenantId === '' || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1
            || $line < 2 || $line > self::MAX_ROWS + 1) {
            throw new DomainException('CSV_KEY_INVALID');
        }
        return 'csv-count-'.substr(hash('sha256', $tenantId.'|'.$sha256), 0, 48).'-'.$line;
    }

    /** @return array<string, mixed> */
    public function process(Tenant $tenant, User $actor, string $csv, bool $commit): array
    {
        $parsed = self::parseCsv($csv);
        $report = $parsed['errors'];
        $applied = 0;
        $replayed = 0;
        $unchanged = 0;

        foreach ($parsed['rows'] as $row) {
            try {
                [$source, $variant] = $this->resolve($tenant, $actor, $row);
                $key = self::rowKey($tenant->id(), $parsed['sha256'], $row['line']);
                // Validate normal business conflicts before opening an ORM
                // transaction. A failed wrapInTransaction closes the EM and
                // would otherwise prevent processing later valid CSV rows.
                $prior = $commit
                    ? $this->entityManager->getRepository(InventoryMovement::class)->findOneBy([
                        'tenant' => $tenant, 'idempotencyKey' => $key,
                    ])
                    : null;
                $balance = $this->balance($tenant, $source, $variant);
                if (!$prior instanceof InventoryMovement) {
                    self::assertTargetAllowed($variant, $balance, $row['quantity']);
                }
                if ($commit) {
                    $item = $this->commitRow(
                        $tenant, $actor, $source, $variant, $row,
                        $key, $parsed['sha256'],
                    );
                } else {
                    $current = $balance?->quantity() ?? 0;
                    $item = [
                        'line' => $row['line'], 'sku' => $row['sku'],
                        'branch_id' => $row['branch_id'], 'status' => 'valid',
                        'current_quantity' => $current,
                        'target_quantity' => $row['quantity'],
                        'delta' => $row['quantity'] - $current,
                    ];
                }
                if (($item['status'] ?? null) === 'applied') {
                    ++$applied;
                } elseif (($item['status'] ?? null) === 'replayed') {
                    ++$replayed;
                } elseif (($item['status'] ?? null) === 'unchanged') {
                    ++$unchanged;
                }
                $report[] = $item;
            } catch (DomainException|\Symfony\Component\Security\Core\Exception\AccessDeniedException $exception) {
                // Same outward code for foreign tenants, unauthorized branches
                // and nonexistent SKUs to avoid cross-tenant enumeration.
                $report[] = ['line' => $row['line'], 'error' => 'CSV_ROW_SCOPE_OR_STOCK_INVALID'];
            }
        }
        usort($report, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);
        return [
            'mode' => $commit ? 'commit' : 'preview',
            'file_sha256' => $parsed['sha256'],
            'applied' => $applied, 'replayed' => $replayed, 'unchanged' => $unchanged,
            'rows' => $report,
        ];
    }

    /**
     * @param array{line: int, sku: string, branch_id: string, quantity: int} $row
     * @return array{InventorySource, ProductVariant}
     */
    private function resolve(Tenant $tenant, User $actor, array $row): array
    {
        $branch = $this->entityManager->getRepository(Branch::class)->findOneBy([
            'id' => $row['branch_id'], 'tenant' => $tenant,
        ]);
        if (!$branch instanceof Branch) {
            throw new DomainException('CSV_BRANCH_NOT_FOUND');
        }
        $this->authorization->require($actor, $tenant, $branch, 'inventory.update');

        $source = $this->entityManager->getRepository(InventorySource::class)->findOneBy([
            'tenant' => $tenant, 'branch' => $branch,
            'type' => InventorySource::TYPE_BRANCH, 'active' => true,
        ]);
        $variant = $this->entityManager->getRepository(ProductVariant::class)->findOneBy([
            'tenant' => $tenant, 'sku' => $row['sku'], 'active' => true,
        ]);
        if (!$source instanceof InventorySource || !$variant instanceof ProductVariant
            || !$variant->product()->isActive()) {
            throw new DomainException('CSV_SOURCE_OR_SKU_NOT_FOUND');
        }
        return [$source, $variant];
    }

    private function balance(
        Tenant $tenant,
        InventorySource $source,
        ProductVariant $variant,
    ): ?InventoryBalance {
        $balance = $this->entityManager->getRepository(InventoryBalance::class)->findOneBy([
            'tenant' => $tenant, 'source' => $source, 'variant' => $variant,
        ]);
        return $balance instanceof InventoryBalance ? $balance : null;
    }

    private static function assertTargetAllowed(
        ProductVariant $variant,
        ?InventoryBalance $balance,
        int $target,
    ): void {
        if ($target < 0 || $target > 2147483647
            || ($balance !== null && $target < $balance->reservedQuantity()
                && !$variant->product()->allowsBackorder())) {
            throw new DomainException('CSV_RESERVED_STOCK_CONFLICT');
        }
    }

    /**
     * @param array{line: int, sku: string, branch_id: string, quantity: int} $row
     * @return array<string, int|string>
     */
    private function commitRow(
        Tenant $tenant,
        User $actor,
        InventorySource $source,
        ProductVariant $variant,
        array $row,
        string $key,
        string $fileSha,
    ): array {
        return $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $em) use (
                $tenant, $actor, $source, $variant, $row, $key, $fileSha,
            ): array {
                // Serialize count-and-adjust with normal inventory mutations.
                $em->lock($source, LockMode::PESSIMISTIC_WRITE);
                $existing = $em->getRepository(InventoryMovement::class)->findOneBy([
                    'tenant' => $tenant, 'idempotencyKey' => $key,
                ]);
                if ($existing instanceof InventoryMovement) {
                    $context = $existing->context();
                    if ($existing->source()->id() !== $source->id()
                        || $existing->variant()->id() !== $variant->id()
                        || ($context['file_sha256'] ?? null) !== $fileSha
                        || ($context['target_quantity'] ?? null) !== $row['quantity']) {
                        throw new DomainException('CSV_IDEMPOTENCY_MISMATCH');
                    }
                    return ['line' => $row['line'], 'status' => 'replayed',
                        'movement_id' => $existing->id()];
                }

                $balance = $this->balance($tenant, $source, $variant);
                self::assertTargetAllowed($variant, $balance, $row['quantity']);
                $current = $balance?->quantity() ?? 0;
                $delta = $row['quantity'] - $current;
                if ($delta === 0) {
                    return ['line' => $row['line'], 'status' => 'unchanged'];
                }

                $movement = $this->inventory->adjust(
                    $tenant,
                    $source,
                    $variant,
                    $delta,
                    $actor->id(),
                    $key,
                    ['reason' => 'conteo CSV', 'branch_id' => $row['branch_id'],
                        'file_sha256' => $fileSha, 'target_quantity' => $row['quantity']],
                    function (InventoryMovement $created) use ($em, $tenant, $actor, $source, $variant, $row): void {
                        $em->persist(new AuditEvent(
                            $tenant, $actor->id(), 'inventory.csv_adjusted',
                            InventoryMovement::class, $created->id(),
                            ['branch_id' => $row['branch_id'],
                                'source_id' => $source->id(), 'variant_id' => $variant->id(),
                                'delta' => $created->delta()],
                        ));
                    },
                );
                return ['line' => $row['line'], 'status' => 'applied',
                    'movement_id' => $movement->id(), 'delta' => $movement->delta()];
            },
        );
    }
}
