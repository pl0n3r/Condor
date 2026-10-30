<?php

declare(strict_types=1);

namespace App\Application\Payments;

use DomainException;

/**
 * Immutable server-configured allowlist of sandbox gateways per tenant/company.
 * Configuration cannot be supplied by a payment HTTP request.
 */
final readonly class PaymentGatewayRegistry
{
    /** @var array<string,PaymentGateway> */
    private array $providers;

    /** @var array<string,array{providers:list<string>,currencies:list<string>}> */
    private array $companies;

    /**
     * @param list<mixed> $providers
     * @param array<string,mixed> $companies
     *        Keys are `tenant_id/company_id`, values are the enabled providers
     *        and currencies for that company (server-side only).
     */
    public function __construct(array $providers, array $companies)
    {
        $available = [];
        foreach ($providers as $provider) {
            if (!$provider instanceof PaymentGateway || $provider->mode() !== 'sandbox') {
                throw new DomainException('GATEWAY_SANDBOX_ONLY');
            }
            $id = $provider->id();
            if (!self::identifier($id) || isset($available[$id])) {
                throw new DomainException('GATEWAY_INVALID_PROVIDER');
            }
            $available[$id] = $provider;
        }
        $normalized = [];
        foreach ($companies as $scope => $config) {
            $parts = explode('/', $scope);
            if (count($parts) !== 2 || !self::identifier($parts[0]) || !self::identifier($parts[1])
                || !is_array($config) || array_keys($config) !== ['providers', 'currencies']
                || !is_array($config['providers']) || !is_array($config['currencies'])
                || !array_is_list($config['providers']) || !array_is_list($config['currencies'])
                || $config['providers'] === [] || $config['currencies'] === []) {
                throw new DomainException('GATEWAY_INVALID_CONFIG');
            }
            $enabledProviders = [];
            foreach ($config['providers'] as $id) {
                if (!is_string($id) || !isset($available[$id]) || in_array($id, $enabledProviders, true)) {
                    throw new DomainException('GATEWAY_UNAVAILABLE');
                }
                $enabledProviders[] = $id;
            }
            $enabledCurrencies = [];
            foreach ($config['currencies'] as $currency) {
                if (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
                    || in_array($currency, $enabledCurrencies, true)) {
                    throw new DomainException('GATEWAY_INVALID_CURRENCY');
                }
                $enabledCurrencies[] = $currency;
            }
            $normalized[$scope] = [
                'providers' => $enabledProviders,
                'currencies' => $enabledCurrencies,
            ];
        }
        $this->providers = $available;
        $this->companies = $normalized;
    }

    public function forCompany(string $tenantId, string $companyId, string $providerId, string $currency): PaymentGateway
    {
        if (!self::identifier($tenantId) || !self::identifier($companyId)
            || !self::identifier($providerId) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new DomainException('GATEWAY_INVALID_SCOPE');
        }
        $config = $this->companies[$tenantId.'/'.$companyId] ?? null;
        if ($config === null || !in_array($providerId, $config['providers'], true)
            || !in_array($currency, $config['currencies'], true)) {
            throw new DomainException('GATEWAY_NOT_ENABLED_FOR_COMPANY');
        }
        return $this->providers[$providerId];
    }

    /** @return list<string> */
    public function available(string $tenantId, string $companyId, string $currency): array
    {
        if (!self::identifier($tenantId) || !self::identifier($companyId)
            || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            return [];
        }
        $config = $this->companies[$tenantId.'/'.$companyId] ?? null;
        return $config !== null && in_array($currency, $config['currencies'], true)
            ? $config['providers'] : [];
    }

    private static function identifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{2,80}$/D', $value) === 1;
    }
}
