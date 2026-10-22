<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * Importación deliberadamente acotada: CSV en memoria, sin ficheros, secretos
 * ni escrituras en preview. Todos los lookups y cambios están ligados al tenant.
 */
final readonly class ProductCsvImporter
{
    private const MAX_BYTES = 262144;
    private const MAX_ROWS = 500;
    private const REQUIRED = ['sku', 'product_name', 'product_slug', 'variant_name'];
    private const OPTIONAL = ['description', 'allow_backorder'];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return list<array<string, string|int>>
     */
    public static function parse(string $csv): array
    {
        if ($csv === '' || strlen($csv) > self::MAX_BYTES || str_contains($csv, "\0")
            || !mb_check_encoding($csv, 'UTF-8')) {
            throw new DomainException('CSV inválido o demasiado grande.');
        }
        $handle = fopen('php://temp/maxmemory:262144', 'w+');
        if ($handle === false) {
            throw new DomainException('No se pudo interpretar el CSV.');
        }
        try {
            fwrite($handle, $csv);
            rewind($handle);
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header === false) {
                throw new DomainException('CSV sin encabezados.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? '';
            $header = array_map(static fn (?string $item): string => strtolower(trim((string) $item)), $header);
            if (count(array_unique($header)) !== count($header)
                || array_diff(self::REQUIRED, $header) !== []
                || array_diff($header, [...self::REQUIRED, ...self::OPTIONAL]) !== []) {
                throw new DomainException('Encabezados CSV no permitidos. Se requieren sku, product_name, product_slug, variant_name.');
            }

            $rows = [];
            $record = 1;
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                ++$record;
                if (count($cells) === 1 && trim((string) $cells[0]) === '') {
                    continue;
                }
                if (count($rows) >= self::MAX_ROWS) {
                    throw new DomainException('El CSV admite máximo 500 filas.');
                }
                $row = ['row' => $record];
                if (count($cells) !== count($header)) {
                    $row['parse_error'] = 'Cantidad de columnas inválida.';
                } else {
                    foreach ($header as $position => $name) {
                        $row[$name] = trim((string) $cells[$position]);
                    }
                }
                $rows[] = $row;
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, valid: int, existing: int, errors: int}
     */
    public function preview(Tenant $tenant, string $csv): array
    {
        $rows = [];
        $seenSku = [];
        $seenSlug = [];
        $valid = 0;
        $existing = 0;
        $errors = 0;

        foreach (self::parse($csv) as $input) {
            $line = $input['row'];
            try {
                if (isset($input['parse_error'])) {
                    throw new DomainException((string) $input['parse_error']);
                }
                $name = (string) ($input['product_name'] ?? '');
                $slug = (string) ($input['product_slug'] ?? '');
                $sku = (string) ($input['sku'] ?? '');
                $variantName = (string) ($input['variant_name'] ?? '');
                $description = (string) ($input['description'] ?? '');
                $backorderRaw = strtolower((string) ($input['allow_backorder'] ?? 'false'));
                if (!in_array($backorderRaw, ['false', 'true', '0', '1'], true)) {
                    throw new DomainException('allow_backorder debe ser true/false o 1/0.');
                }
                $backorder = $backorderRaw === 'true' || $backorderRaw === '1';

                // Reutiliza validaciones de dominio; instancias sin persistencia.
                $draft = new Product($tenant, $name, $slug, $description, $backorder);
                $variant = new ProductVariant($tenant, $draft, $sku, $variantName);
                $sku = $variant->sku();
                $slug = $draft->slug();
                if (isset($seenSku[$sku])) {
                    throw new DomainException('SKU repetido dentro del archivo.');
                }
                $seenSku[$sku] = true;
                $productKey = [$draft->name(), $draft->description(), $draft->allowsBackorder()];
                if (isset($seenSlug[$slug]) && $seenSlug[$slug] !== $productKey) {
                    throw new DomainException('El slug aparece en el archivo con otros datos de producto.');
                }

                $storedProduct = $this->entityManager->getRepository(Product::class)
                    ->findOneBy(['tenant' => $tenant, 'slug' => $slug]);
                if ($storedProduct instanceof Product
                    && (!$storedProduct->isActive() || $storedProduct->name() !== $draft->name())) {
                    throw new DomainException('Producto inactivo o slug asociado a otro nombre.');
                }
                $storedVariant = $this->entityManager->getRepository(ProductVariant::class)
                    ->findOneBy(['tenant' => $tenant, 'sku' => $sku]);
                if ($storedVariant instanceof ProductVariant) {
                    if (!$storedVariant->isActive()
                        || $storedVariant->product()->slug() !== $slug
                        || !$storedVariant->product()->isActive()) {
                        throw new DomainException('SKU inactivo o asociado a otro producto.');
                    }
                    $status = 'existing';
                    ++$existing;
                } else {
                    $status = 'valid';
                    ++$valid;
                }
                $seenSlug[$slug] = $productKey;
                $rows[] = [
                    'row' => $line,
                    'sku' => $sku,
                    'product_name' => $draft->name(),
                    'product_slug' => $slug,
                    'variant_name' => $variant->name(),
                    'description' => $draft->description(),
                    'allow_backorder' => $backorder,
                    'status' => $status,
                    'errors' => [],
                ];
            } catch (DomainException $exception) {
                ++$errors;
                $rows[] = [
                    'row' => $line,
                    'status' => 'error',
                    'errors' => [$exception->getMessage()],
                ];
            }
        }
        return [
            'rows' => $rows,
            'total' => count($rows),
            'valid' => $valid,
            'existing' => $existing,
            'errors' => $errors,
        ];
    }

    /**
     * Cada confirmación revalida los datos y los permisos son comprobados en el
     * controlador. Un mismo SKU del mismo tenant nunca genera otra variante.
     * @return array{rows: list<array<string, mixed>>, total: int, valid: int, existing: int, errors: int, created: int}
     */
    public function commit(Tenant $tenant, Branch $branch, User $actor, string $csv): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($tenant, $branch, $actor, $csv): array {
            $result = $this->preview($tenant, $csv);
            $created = 0;
            $knownProducts = [];

            foreach ($result['rows'] as $row) {
                if ($row['status'] !== 'valid') {
                    continue;
                }
                $slug = $row['product_slug'];
                if (isset($knownProducts[$slug])) {
                    $product = $knownProducts[$slug];
                } else {
                    $stored = $this->entityManager->getRepository(Product::class)
                        ->findOneBy(['tenant' => $tenant, 'slug' => $slug]);
                    if ($stored instanceof Product) {
                        $product = $stored;
                    } else {
                        $product = new Product(
                            $tenant, $row['product_name'], $slug,
                            $row['description'], $row['allow_backorder'],
                        );
                        $this->entityManager->persist($product);
                        $this->entityManager->persist(new AuditEvent(
                            $tenant, $actor->id(), 'product.created',
                            Product::class, $product->id(),
                            ['branch_id' => $branch->id(), 'source' => 'csv_import'],
                        ));
                    }
                    $knownProducts[$slug] = $product;
                }
                $variant = new ProductVariant(
                    $tenant, $product, $row['sku'], $row['variant_name'],
                );
                $this->entityManager->persist($variant);
                $this->entityManager->persist(new AuditEvent(
                    $tenant, $actor->id(), 'product_variant.created',
                    ProductVariant::class, $variant->id(),
                    ['branch_id' => $branch->id(), 'product_id' => $product->id(), 'source' => 'csv_import'],
                ));
                ++$created;
            }
            $this->entityManager->flush();
            return [...$result, 'created' => $created];
        });
    }
}
