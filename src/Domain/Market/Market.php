<?php

declare(strict_types=1);

namespace App\Domain\Market;

use DomainException;

final readonly class Market
{
    private const STATES = [
        'researching',
        'validating',
        'preparing',
        'launch_ready',
        'live',
        'paused',
    ];
    private const PRIORITIES = ['primary', 'secondary', 'candidate'];
    private const FRESHNESS = ['fresh', 'stale', 'unknown'];
    private const REQUIRED_KEYS = [
        'market_id',
        'tenant_id',
        'venture_id',
        'country_code',
        'status',
        'priority',
        'locales',
        'currencies',
        'legal_entity_ref',
        'lex_assessment_ref',
        'infrastructure_ref',
        'timezone',
        'source_ref',
        'observed_at',
        'freshness',
    ];
    private const TRANSITIONS = [
        'researching' => ['validating', 'paused'],
        'validating' => ['researching', 'preparing', 'paused'],
        'preparing' => ['validating', 'launch_ready', 'paused'],
        'launch_ready' => ['preparing', 'live', 'paused'],
        'live' => ['paused'],
        'paused' => ['researching', 'validating', 'preparing'],
    ];

    /**
     * @param list<string> $locales
     * @param list<string> $currencies
     */
    private function __construct(
        private string $marketId,
        private string $tenantId,
        private string $ventureId,
        private string $countryCode,
        private string $status,
        private string $priority,
        private array $locales,
        private array $currencies,
        private ?string $legalEntityRef,
        private ?string $lexAssessmentRef,
        private ?string $infrastructureRef,
        private string $timezone,
        private string $sourceRef,
        private int $observedAt,
        private string $freshness,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        self::assertExactKeys($payload);

        return new self(
            self::identifier($payload['market_id'], 'market_id inválido.'),
            self::identifier($payload['tenant_id'], 'tenant_id inválido.'),
            self::identifier($payload['venture_id'], 'venture_id inválido.'),
            self::country($payload['country_code']),
            self::closedString($payload['status'], self::STATES, 'Estado de Market inválido.'),
            self::closedString($payload['priority'], self::PRIORITIES, 'Prioridad de Market inválida.'),
            self::locales($payload['locales']),
            self::currencies($payload['currencies']),
            self::referenceOrNull($payload['legal_entity_ref']),
            self::referenceOrNull($payload['lex_assessment_ref']),
            self::referenceOrNull($payload['infrastructure_ref']),
            self::timezone($payload['timezone']),
            self::reference($payload['source_ref']),
            self::positiveInt($payload['observed_at'], 'observed_at inválido.'),
            self::closedString($payload['freshness'], self::FRESHNESS, 'Freshness de Market inválida.'),
        );
    }

    public function status(): string
    {
        return $this->status;
    }

    public function contextKey(): string
    {
        return implode(':', [
            'tenant',
            $this->tenantId,
            'venture',
            $this->ventureId,
            'market',
            $this->marketId,
            $this->countryCode,
        ]);
    }

    /** @param array<string, mixed> $readiness */
    public function transitionTo(string $nextState, array $readiness = []): self
    {
        $nextState = self::closedString($nextState, self::STATES, 'Estado destino inválido.');
        if (!in_array($nextState, self::TRANSITIONS[$this->status], true)) {
            throw new DomainException(sprintf(
                'Transición de Market inválida: %s -> %s.',
                $this->status,
                $nextState,
            ));
        }

        if (in_array($nextState, ['launch_ready', 'live'], true)) {
            $this->assertLaunchAuthorization($readiness);
        }

        return new self(
            $this->marketId,
            $this->tenantId,
            $this->ventureId,
            $this->countryCode,
            $nextState,
            $this->priority,
            $this->locales,
            $this->currencies,
            $this->legalEntityRef,
            $this->lexAssessmentRef,
            $this->infrastructureRef,
            $this->timezone,
            $this->sourceRef,
            $this->observedAt,
            $this->freshness,
        );
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'market_id' => $this->marketId,
            'tenant_id' => $this->tenantId,
            'venture_id' => $this->ventureId,
            'country_code' => $this->countryCode,
            'status' => $this->status,
            'priority' => $this->priority,
            'locales' => $this->locales,
            'currencies' => $this->currencies,
            'legal_entity_ref' => $this->legalEntityRef,
            'lex_assessment_ref' => $this->lexAssessmentRef,
            'infrastructure_ref' => $this->infrastructureRef,
            'timezone' => $this->timezone,
            'source_ref' => $this->sourceRef,
            'observed_at' => $this->observedAt,
            'freshness' => $this->freshness,
        ];
    }

    /** @param array<string, mixed> $readiness */
    private function assertLaunchAuthorization(array $readiness): void
    {
        $authorization = $readiness['authorization'] ?? null;
        if (!is_array($authorization) || array_is_list($authorization)) {
            throw new DomainException('Launch readiness ausente.');
        }

        $required = [
            'market_context',
            'launch_allowed',
            'lex_status',
            'evidence_freshness',
            'evidence_refs',
        ];
        $keys = array_keys($authorization);
        sort($keys, SORT_STRING);
        sort($required, SORT_STRING);
        if ($keys !== $required) {
            throw new DomainException('Launch authorization fuera de contrato.');
        }

        if (($authorization['market_context'] ?? null) !== $this->contextKey()
            || ($authorization['launch_allowed'] ?? null) !== true
            || ($authorization['lex_status'] ?? null) !== 'SATISFIED'
            || ($authorization['evidence_freshness'] ?? null) !== 'FRESH') {
            throw new DomainException('Market no cumple readiness + LEX para lanzamiento.');
        }

        $refs = $authorization['evidence_refs'] ?? null;
        if (!is_array($refs) || !array_is_list($refs) || $refs === []) {
            throw new DomainException('Launch authorization requiere evidencia.');
        }
    }

    /** @param array<string, mixed> $payload */
    private static function assertExactKeys(array $payload): void
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        $required = self::REQUIRED_KEYS;
        sort($required, SORT_STRING);
        if ($keys !== $required) {
            throw new DomainException('Market incompleto o con campos no soportados.');
        }
    }

    private static function country(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Z]{2}$/D', $value) !== 1) {
            throw new DomainException('country_code inválido.');
        }

        return $value;
    }

    private static function identifier(mixed $value, string $message): string
    {
        if (!is_string($value)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) !== 1) {
            throw new DomainException($message);
        }

        return $value;
    }

    private static function referenceOrNull(mixed $value): ?string
    {
        return $value === null ? null : self::reference($value);
    }

    private static function reference(mixed $value): string
    {
        if (!is_string($value)
            || strlen($value) > 200
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/@#-]*$/D', $value) !== 1) {
            throw new DomainException('Referencia de Market inválida.');
        }

        return $value;
    }

    /** @return list<string> */
    private static function locales(mixed $value): array
    {
        return self::normalizedList(
            $value,
            '/^[a-z]{2}(?:-[A-Z]{2})?$/D',
            'Locales de Market inválidos.',
        );
    }

    /** @return list<string> */
    private static function currencies(mixed $value): array
    {
        return self::normalizedList(
            $value,
            '/^[A-Z]{3}$/D',
            'Currencies de Market inválidas.',
        );
    }

    /** @return list<string> */
    private static function normalizedList(mixed $value, string $pattern, string $message): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 20) {
            throw new DomainException($message);
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item) || preg_match($pattern, $item) !== 1) {
                throw new DomainException($message);
            }
            $items[$item] = true;
        }

        $result = array_keys($items);
        sort($result, SORT_STRING);

        return $result;
    }

    private static function timezone(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^(?:UTC|[A-Za-z_]+\/[A-Za-z0-9_+\-]+)$/D', $value) !== 1) {
            throw new DomainException('Timezone de Market inválida.');
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
}
