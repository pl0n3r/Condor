<?php

declare(strict_types=1);

namespace App\Application\Observability;

use App\Domain\Observability\Entity\FunctionalSignal;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Primer slice de "Analítica/reportes" (roadmap Issue #1): serie diaria
 * exportable de señales funcionales agregadas (sin PII, mismas garantías
 * que FunctionalSignalRecorder). No sustituye un producto de analítica
 * completo — es un reporte real y descargable sobre datos reales.
 */
final readonly class FunctionalSignalReport
{
    /** @var list<string> */
    private const TYPES = [
        FunctionalSignal::TENANT_CREATED,
        FunctionalSignal::LOGIN_SUCCESS,
        FunctionalSignal::LOGIN_FAILURE,
        FunctionalSignal::AUTHORIZATION_DENIED,
        FunctionalSignal::ROLE_MODIFIED,
    ];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array<string, array<string, int>> fecha (Y-m-d, UTC) => [tipo => conteo]
     */
    public function dailySeries(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $since = $now->modify(sprintf('-%d days', $days - 1))->setTime(0, 0);

        $series = [];
        for ($cursor = $since; $cursor <= $now; $cursor = $cursor->modify('+1 day')) {
            $series[$cursor->format('Y-m-d')] = array_fill_keys(self::TYPES, 0);
        }

        // SQL nativo: DQL no incluye DATE() sin una extensión de funciones
        // custom que este proyecto no registra; MariaDB sí la soporta.
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT DATE(created_at) AS day, type, COUNT(id) AS aggregate_count '
            .'FROM condor_functional_signal '
            .'WHERE created_at >= :since '
            .'GROUP BY day, type',
            ['since' => $since->format('Y-m-d H:i:s')],
        );

        foreach ($rows as $row) {
            $day = (string) ($row['day'] ?? '');
            $type = (string) ($row['type'] ?? '');
            if (!isset($series[$day]) || !array_key_exists($type, $series[$day])) {
                continue;
            }

            $series[$day][$type] = (int) ($row['aggregate_count'] ?? 0);
        }

        return $series;
    }

    /** @return string contenido CSV con encabezado */
    public function toCsv(int $days = 30): string
    {
        $series = $this->dailySeries($days);
        $buffer = fopen('php://temp', 'r+');
        if ($buffer === false) {
            return '';
        }

        fputcsv($buffer, array_merge(['fecha'], self::TYPES), ',', '"', '\\');
        foreach ($series as $day => $counts) {
            fputcsv($buffer, array_merge([$day], array_values($counts)), ',', '"', '\\');
        }

        rewind($buffer);
        $csv = stream_get_contents($buffer);
        fclose($buffer);

        return $csv === false ? '' : $csv;
    }
}
