<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Domain\Commerce\Entity\Customer;
use App\Domain\Orders\Entity\Order;
use App\Domain\Organization\Entity\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * Consulta interna de clientes existentes, siempre limitada al tenant.
 *
 * No almacena términos ni registra datos personales. El documento sólo se lee
 * desde Customer::documentNumber(), provisto por la hoja separada #889.
 */
final readonly class CustomerSearch
{
    public const SEGMENT_ALL = 'all';
    public const SEGMENT_NEW = 'new_this_month';
    public const SEGMENT_INACTIVE = 'without_purchases_90_days';
    public const SEGMENT_TOP = 'top_20_purchases';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<Customer> */
    public function search(
        Tenant $tenant,
        string $term = '',
        string $segment = self::SEGMENT_ALL,
        ?DateTimeImmutable $asOf = null,
    ): array {
        if (!in_array($segment, [
            self::SEGMENT_ALL, self::SEGMENT_NEW,
            self::SEGMENT_INACTIVE, self::SEGMENT_TOP,
        ], true)) {
            throw new DomainException('Segmento de clientes no permitido.');
        }
        if (!mb_check_encoding($term, 'UTF-8')
            || mb_strlen($term, 'UTF-8') > 120
            || preg_match('/[\x00-\x1F\x7F]/u', $term) === 1) {
            throw new DomainException('Filtro de búsqueda de clientes inválido.');
        }

        $asOf = ($asOf ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $customers = array_values(array_filter(
            $this->entityManager->getRepository(Customer::class)->findBy(
                ['tenant' => $tenant, 'active' => true],
                ['name' => 'ASC', 'id' => 'ASC'],
            ),
            static fn (Customer $item): bool => $item->tenant()->id() === $tenant->id()
                && $item->isActive(),
        ));

        if ($segment === self::SEGMENT_NEW) {
            $start = $asOf->modify('first day of this month midnight');
            $customers = array_values(array_filter(
                $customers,
                static fn (Customer $customer): bool =>
                    $customer->createdAt() >= $start
                    && $customer->createdAt() <= $asOf,
            ));
        }

        $counts = [];
        $recent = [];
        if ($segment === self::SEGMENT_INACTIVE || $segment === self::SEGMENT_TOP) {
            $cutoff = $asOf->modify('-90 days');
            foreach ($this->entityManager->getRepository(Order::class)
                ->findBy(['tenant' => $tenant]) as $order) {
                if ($order->tenant()->id() !== $tenant->id()
                    || $order->orderStatus() === Order::STATUS_CANCELLED
                    || $order->createdAt() > $asOf) {
                    continue;
                }
                $customer = $order->customer();
                if (!$customer instanceof Customer
                    || $customer->tenant()->id() !== $tenant->id()) {
                    continue;
                }
                $id = $customer->id();
                $counts[$id] = ($counts[$id] ?? 0) + 1;
                if ($order->createdAt() >= $cutoff) {
                    $recent[$id] = true;
                }
            }
            if ($segment === self::SEGMENT_INACTIVE) {
                $customers = array_values(array_filter(
                    $customers,
                    static fn (Customer $customer): bool =>
                        !isset($recent[$customer->id()]),
                ));
            } else {
                $customers = array_values(array_filter(
                    $customers,
                    static fn (Customer $customer): bool =>
                        ($counts[$customer->id()] ?? 0) > 0,
                ));
                usort(
                    $customers,
                    static fn (Customer $a, Customer $b): int =>
                        ($counts[$b->id()] <=> $counts[$a->id()])
                        ?: (strcmp(self::fold($a->name()), self::fold($b->name()))
                            ?: strcmp($a->id(), $b->id())),
                );
                // La búsqueda se aplica antes del límite: no ocultar coincidencias
                // situadas después del top 20 global.
            }
        }

        $needle = self::fold($term);
        if ($needle !== '') {
            $customers = array_values(array_filter(
                $customers,
                static fn (Customer $customer): bool =>
                    str_contains(self::fold($customer->name()), $needle)
                    || str_contains(self::fold($customer->email() ?? ''), $needle)
                    || str_contains(self::fold($customer->phone() ?? ''), $needle)
                    || str_contains(self::fold($customer->documentNumber() ?? ''), $needle),
            ));
        }

        if ($segment === self::SEGMENT_TOP) {
            return array_slice($customers, 0, 20);
        }

        return $customers;
    }

    private static function fold(string $value): string
    {
        $folded = strtr(mb_strtolower(trim($value), 'UTF-8'), [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n',
        ]);

        // Unicode NFD: una tilde combinada debe buscar igual que NFC (p. ej., e + U+0301).
        // preg_replace devuelve null ante UTF-8 inválido: nunca ampliar un filtro corrupto.
        return preg_replace('/\p{Mn}+/u', '', $folded) ?? $folded;
    }
}
