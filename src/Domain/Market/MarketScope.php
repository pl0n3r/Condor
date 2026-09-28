<?php

declare(strict_types=1);

namespace App\Domain\Market;

use DomainException;

final readonly class MarketScope
{
    private const MODES = ['single_country', 'multi_country', 'global'];
    private const REQUIRED_KEYS = [
        'mode',
        'primary_country',
        'target_countries',
        'excluded_countries',
        'launch_countries',
        'expansion_candidates',
        'default_currency',
        'default_locale',
    ];

    /**
     * @param list<string> $targetCountries
     * @param list<string> $excludedCountries
     * @param list<string> $launchCountries
     * @param list<string> $expansionCandidates
     */
    private function __construct(
        private string $mode,
        private ?string $primaryCountry,
        private array $targetCountries,
        private array $excludedCountries,
        private array $launchCountries,
        private array $expansionCandidates,
        private string $defaultCurrency,
        private string $defaultLocale,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        self::assertExactKeys($payload);

        $mode = self::closedString($payload['mode'], self::MODES, 'Market Scope mode inválido.');
        $primary = self::countryOrNull($payload['primary_country']);
        $targets = self::countries($payload['target_countries'], 'target_countries');
        $excluded = self::countries($payload['excluded_countries'], 'excluded_countries');
        $launch = self::countries($payload['launch_countries'], 'launch_countries');
        $expansion = self::countries($payload['expansion_candidates'], 'expansion_candidates');
        $currency = self::currency($payload['default_currency']);
        $locale = self::locale($payload['default_locale']);

        if ($mode === 'single_country') {
            if ($primary === null || $targets !== [$primary]) {
                throw new DomainException('single_country requiere exactamente primary_country como target.');
            }
        }

        if ($mode === 'multi_country' && count($targets) < 2) {
            throw new DomainException('multi_country requiere al menos dos target_countries explícitos.');
        }

        if ($primary !== null && $targets !== [] && !in_array($primary, $targets, true)) {
            throw new DomainException('primary_country debe pertenecer a target_countries.');
        }

        if (array_intersect($targets, $excluded) !== []) {
            throw new DomainException('Un país target no puede estar excluido.');
        }

        if (array_diff($launch, $targets) !== []) {
            throw new DomainException('launch_countries debe ser subconjunto explícito de target_countries.');
        }

        if (array_intersect($launch, $expansion) !== []) {
            throw new DomainException('Un país live/launch no puede seguir como expansion_candidate.');
        }

        return new self(
            $mode,
            $primary,
            $targets,
            $excluded,
            $launch,
            $expansion,
            $currency,
            $locale,
        );
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function defaultCurrency(): string
    {
        return $this->defaultCurrency;
    }

    public function defaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function isLaunchAuthorized(string $countryCode): bool
    {
        return in_array(self::country($countryCode), $this->launchCountries, true);
    }

    public function isExplicitTarget(string $countryCode): bool
    {
        return in_array(self::country($countryCode), $this->targetCountries, true);
    }

    /**
     * @return array{
     *   mode:string,
     *   primary_country:?string,
     *   target_countries:list<string>,
     *   excluded_countries:list<string>,
     *   launch_countries:list<string>,
     *   expansion_candidates:list<string>,
     *   default_currency:string,
     *   default_locale:string
     * }
     */
    public function snapshot(): array
    {
        return [
            'mode' => $this->mode,
            'primary_country' => $this->primaryCountry,
            'target_countries' => $this->targetCountries,
            'excluded_countries' => $this->excludedCountries,
            'launch_countries' => $this->launchCountries,
            'expansion_candidates' => $this->expansionCandidates,
            'default_currency' => $this->defaultCurrency,
            'default_locale' => $this->defaultLocale,
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function assertExactKeys(array $payload): void
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        $expected = self::REQUIRED_KEYS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new DomainException('Market Scope incompleto o con campos no soportados.');
        }
    }

    private static function countryOrNull(mixed $value): ?string
    {
        return $value === null ? null : self::country($value);
    }

    private static function country(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Z]{2}$/D', $value) !== 1) {
            throw new DomainException('Código de país inválido.');
        }

        return $value;
    }

    /** @return list<string> */
    private static function countries(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
            throw new DomainException($field.' inválido.');
        }

        $countries = [];
        foreach ($value as $country) {
            $countries[self::country($country)] = true;
        }

        $result = array_keys($countries);
        sort($result, SORT_STRING);

        return $result;
    }

    private static function currency(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Z]{3}$/D', $value) !== 1) {
            throw new DomainException('Currency default inválida.');
        }

        return $value;
    }

    private static function locale(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $value) !== 1) {
            throw new DomainException('Locale default inválido.');
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
