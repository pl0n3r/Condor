<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Commerce\Entity\Customer;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/** Importación aislada por tenant. Nunca escribe durante preview. */
final readonly class CustomerCsvImporter
{
    private const MAX_BYTES = 262144;
    private const MAX_ROWS = 500;
    private const REQUIRED = ['name', 'email'];
    private const OPTIONAL = ['phone', 'notes'];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<array<string, string|int>> */
    public static function parse(string $csv): array
    {
        if ($csv === '' || strlen($csv) > self::MAX_BYTES
            || str_contains($csv, "\0") || !mb_check_encoding($csv, 'UTF-8')) {
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
            $header = array_map(
                static fn (?string $value): string => strtolower(trim((string) $value)),
                $header,
            );
            if (count(array_unique($header)) !== count($header)
                || array_diff(self::REQUIRED, $header) !== []
                || array_diff($header, [...self::REQUIRED, ...self::OPTIONAL]) !== []) {
                throw new DomainException('Encabezados permitidos: name,email,phone,notes. Se requieren name y email.');
            }
            $rows = [];
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if (count($cells) === 1 && trim((string) $cells[0]) === '') {
                    continue;
                }
                if (count($rows) >= self::MAX_ROWS) {
                    throw new DomainException('El CSV admite máximo 500 filas.');
                }
                $row = ['row' => count($rows) + 2];
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

    /** @return array{rows: list<array<string, mixed>>, total: int, valid: int, existing: int, errors: int} */
    public function preview(Tenant $tenant, string $csv): array
    {
        $result = [];
        $seen = [];
        $valid = 0;
        $existing = 0;
        $errors = 0;
        foreach (self::parse($csv) as $input) {
            try {
                if (isset($input['parse_error'])) {
                    throw new DomainException((string) $input['parse_error']);
                }
                $draft = new Customer(
                    $tenant, (string) ($input['name'] ?? ''),
                    (string) ($input['email'] ?? ''),
                    (string) ($input['phone'] ?? ''),
                    (string) ($input['notes'] ?? ''),
                );
                $email = $draft->email();
                if ($email === null) {
                    throw new DomainException('El correo es obligatorio para una importación idempotente.');
                }
                if (isset($seen[$email])) {
                    throw new DomainException('Correo duplicado dentro del CSV.');
                }
                $seen[$email] = true;
                $matches = $this->entityManager->getRepository(Customer::class)
                    ->findBy(['tenant' => $tenant, 'email' => $email], null, 2);
                if (count($matches) > 1) {
                    throw new DomainException('Correo ambiguo en el tenant; requiere revisión.');
                }
                if (count($matches) === 1) {
                    if (!$matches[0]->isActive()) {
                        throw new DomainException('Correo de cliente inactivo; no se reactiva al importar.');
                    }
                    $status = 'existing';
                    ++$existing;
                } else {
                    $status = 'valid';
                    ++$valid;
                }
                $result[] = [
                    'row' => $input['row'],
                    'name' => $draft->name(),
                    'email' => $email,
                    'phone' => $draft->phone(),
                    'notes' => $draft->notes(),
                    'status' => $status,
                    'errors' => [],
                ];
            } catch (DomainException $exception) {
                ++$errors;
                $result[] = [
                    'row' => $input['row'],
                    'status' => 'error',
                    'errors' => [$exception->getMessage()],
                ];
            }
        }
        return [
            'rows' => $result, 'total' => count($result), 'valid' => $valid,
            'existing' => $existing, 'errors' => $errors,
        ];
    }

    /** @return array{rows: list<array<string, mixed>>, total: int, valid: int, existing: int, errors: int, created: int} */
    public function commit(Tenant $tenant, Branch $branch, User $actor, string $csv): array
    {
        return $this->entityManager->wrapInTransaction(
            function () use ($tenant, $branch, $actor, $csv): array {
                // Serializa importaciones para este tenant aunque email no tenga UNIQUE.
                $this->entityManager->lock($tenant, LockMode::PESSIMISTIC_WRITE);
                $result = $this->preview($tenant, $csv);
                $created = 0;
                foreach ($result['rows'] as $row) {
                    if ($row['status'] !== 'valid') {
                        continue;
                    }
                    $customer = new Customer(
                        $tenant, $row['name'], $row['email'], $row['phone'], $row['notes'],
                    );
                    $this->entityManager->persist($customer);
                    // La auditoría solo conserva metadatos, nunca PII ni contenido CSV.
                    $this->entityManager->persist(new AuditEvent(
                        $tenant, $actor->id(), 'customer.created',
                        Customer::class, $customer->id(),
                        ['branch_id' => $branch->id(), 'source' => 'csv_import'],
                    ));
                    ++$created;
                }
                $this->entityManager->flush();
                return [...$result, 'created' => $created];
            },
        );
    }
}
