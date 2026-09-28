<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeGap;

use DomainException;
use JsonException;

final class KnowledgeGap
{
    private const OUTCOMES = ['unanswered', 'escalated'];
    private const REQUIRED_KEYS = [
        'question', 'locale', 'module', 'scope', 'source_ref',
        'evidence_refs', 'observed_at', 'outcome',
    ];
    private const SECRET_VALUE = '/(?:-----BEGIN [A-Z ]*PRIVATE KEY-----|\\bBearer\\s+[A-Za-z0-9._~+\\/=\\-]{10,}|\\b(?:api[_ -]?key|token|password|passwd|secret)\\s*[:=]\\s*\\S+|\\bgithub_pat_[A-Za-z0-9_]{10,}|\\bgh[pousr]_[A-Za-z0-9]{20,}|\\bsk-[A-Za-z0-9]{20,})/i';

    /**
     * @param list<string> $evidenceRefs
     */
    private function __construct(
        private readonly string $fingerprint,
        private readonly string $locale,
        private readonly string $module,
        private readonly string $scope,
        private readonly string $sourceRef,
        private readonly array $evidenceRefs,
        private readonly int $observedAt,
        private readonly int $unansweredCount,
        private readonly int $escalatedCount,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        self::assertExactKeys($payload);

        $questionKey = self::questionKey($payload['question'] ?? null);
        $locale = self::locale($payload['locale'] ?? null);
        $module = self::identifier($payload['module'] ?? null, 'Módulo inválido.');
        $scope = self::scope($payload['scope'] ?? null);
        $sourceRef = self::reference($payload['source_ref'] ?? null, 'source_ref inválido.');
        $evidenceRefs = self::references($payload['evidence_refs'] ?? null);
        $observedAt = self::positiveInt($payload['observed_at'] ?? null, 'observed_at inválido.');
        $outcome = self::closedString($payload['outcome'] ?? null, self::OUTCOMES, 'Outcome inválido.');

        $fingerprint = self::computeFingerprint([
            'question_key' => $questionKey,
            'locale' => $locale,
            'module' => $module,
            'scope' => $scope,
        ]);

        return new self(
            $fingerprint,
            $locale,
            $module,
            $scope,
            $sourceRef,
            $evidenceRefs,
            $observedAt,
            $outcome === 'unanswered' ? 1 : 0,
            $outcome === 'escalated' ? 1 : 0,
        );
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'locale' => $this->locale,
            'module' => $this->module,
            'scope' => $this->scope,
            'source_ref' => $this->sourceRef,
            'evidence_refs' => $this->evidenceRefs,
            'observed_at' => $this->observedAt,
            'unanswered_count' => $this->unansweredCount,
            'escalated_count' => $this->escalatedCount,
        ];
    }

    /**
     * @param list<self> $gaps
     * @return list<array<string, mixed>>
     */
    public static function aggregate(array $gaps): array
    {
        /** @var array<string, array<string, mixed>> $grouped */
        $grouped = [];

        foreach ($gaps as $gap) {
            $snapshot = $gap->snapshot();
            $fingerprint = $gap->fingerprint();

            if (!isset($grouped[$fingerprint])) {
                $grouped[$fingerprint] = $snapshot;
                $grouped[$fingerprint]['first_observed_at'] = $snapshot['observed_at'];
                $grouped[$fingerprint]['last_observed_at'] = $snapshot['observed_at'];
                unset($grouped[$fingerprint]['observed_at']);
                continue;
            }

            $row = $grouped[$fingerprint];
            $row['unanswered_count'] = (int) $row['unanswered_count'] + (int) $snapshot['unanswered_count'];
            $row['escalated_count'] = (int) $row['escalated_count'] + (int) $snapshot['escalated_count'];
            $row['first_observed_at'] = min((int) $row['first_observed_at'], (int) $snapshot['observed_at']);
            $row['last_observed_at'] = max((int) $row['last_observed_at'], (int) $snapshot['observed_at']);

            $refs = array_merge(
                self::stringList($row['evidence_refs']),
                self::stringList($snapshot['evidence_refs']),
            );
            $refs = array_values(array_unique($refs));
            sort($refs, SORT_STRING);
            $row['evidence_refs'] = $refs;

            $sources = self::stringList($row['source_refs'] ?? [$row['source_ref']]);
            $sources[] = (string) $snapshot['source_ref'];
            $sources = array_values(array_unique($sources));
            sort($sources, SORT_STRING);
            $row['source_refs'] = $sources;
            unset($row['source_ref']);

            $grouped[$fingerprint] = $row;
        }

        $rows = array_values($grouped);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['fingerprint'], (string) $b['fingerprint']));

        foreach ($rows as &$row) {
            if (isset($row['source_ref'])) {
                $row['source_refs'] = [(string) $row['source_ref']];
                unset($row['source_ref']);
            }
        }
        unset($row);

        return $rows;
    }

    /** @param array<string, mixed> $payload */
    private static function assertExactKeys(array $payload): void
    {
        $keys = array_keys($payload);
        sort($keys);
        $expected = self::REQUIRED_KEYS;
        sort($expected);
        if ($keys !== $expected) {
            throw new DomainException('Payload de KnowledgeGap incompleto o con campos no soportados.');
        }
    }

    private static function questionKey(mixed $value): string
    {
        if (!is_string($value)) {
            throw new DomainException('Pregunta inválida.');
        }

        $value = trim($value);
        if ($value === '' || self::length($value) > 500 || self::containsSensitiveData($value)) {
            throw new DomainException('Pregunta contiene datos sensibles o no minimizados.');
        }

        $value = mb_strtolower($value, 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        if (!is_string($normalized)) {
            throw new DomainException('No se pudo normalizar la pregunta.');
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($normalized));
        if (!is_string($normalized) || $normalized === '') {
            throw new DomainException('Pregunta inválida.');
        }

        return $normalized;
    }

    private static function containsSensitiveData(string $value): bool
    {
        if (preg_match(self::SECRET_VALUE, $value) === 1) return true;
        if (str_contains($value, '@')) return true;
        if (preg_match('/\b\+?\d[\d\s().-]{8,}\d\b/u', $value) === 1) return true;

        return false;
    }

    /** @param array<string, string> $identity */
    private static function computeFingerprint(array $identity): string
    {
        try {
            return hash('sha256', json_encode(
                $identity,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException $exception) {
            throw new DomainException('No se pudo serializar KnowledgeGap.', previous: $exception);
        }
    }

    private static function locale(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $value) !== 1) {
            throw new DomainException('Locale inválido.');
        }
        return $value;
    }

    private static function scope(mixed $value): string
    {
        if (!is_string($value)) {
            throw new DomainException('Scope inválido.');
        }
        if ($value === 'global') return $value;
        if (preg_match('/^tenant:[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new DomainException('Scope inválido.');
        }
        return $value;
    }

    private static function identifier(mixed $value, string $message): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new DomainException($message);
        }
        return $value;
    }

    private static function reference(mixed $value, string $message): string
    {
        if (!is_string($value)) {
            throw new DomainException($message);
        }
        $value = trim($value);
        if ($value === '' || self::length($value) > 200 || str_contains($value, '@')
            || preg_match(self::SENSITIVE, $value) === 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/@#-]*$/D', $value) !== 1) {
            throw new DomainException($message);
        }
        return $value;
    }

    /** @return list<string> */
    private static function references(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 20) {
            throw new DomainException('evidence_refs inválido.');
        }

        $refs = [];
        foreach ($value as $ref) {
            $normalized = self::reference($ref, 'evidence_ref inválido.');
            $refs[$normalized] = true;
        }
        $result = array_keys($refs);
        sort($result, SORT_STRING);
        return $result;
    }

    /**
     * @param list<string> $allowed
     */
    private static function closedString(mixed $value, array $allowed, string $message): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new DomainException($message);
        }
        return $value;
    }

    private static function positiveInt(mixed $value, string $message): int
    {
        if (!is_int($value) || $value < 1) {
            throw new DomainException($message);
        }
        return $value;
    }

    private static function length(string $value): int
    {
        $length = mb_strlen($value, 'UTF-8');
        return $length;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new DomainException('Lista interna inválida.');
        }

        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new DomainException('Lista interna inválida.');
            }
            $result[] = $item;
        }
        return $result;
    }
}
