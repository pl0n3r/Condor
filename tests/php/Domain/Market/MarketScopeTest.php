<?php

declare(strict_types=1);

namespace App\Tests\Domain\Market;

use App\Application\Market\CondorMarketDefaults;
use App\Domain\Market\MarketScope;
use DomainException;
use PHPUnit\Framework\TestCase;

final class MarketScopeTest extends TestCase
{
    public function testColombiaDefaultsAreConfigurableAndNotUniversal(): void
    {
        $colombia = CondorMarketDefaults::initial();
        self::assertSame('CO', $colombia->snapshot()['primary_country']);
        self::assertSame('COP', $colombia->defaultCurrency());
        self::assertSame('es-CO', $colombia->defaultLocale());

        $mexico = CondorMarketDefaults::initial([
            'primary_country' => 'MX',
            'target_countries' => ['MX'],
            'launch_countries' => ['MX'],
            'default_currency' => 'MXN',
            'default_locale' => 'es-MX',
        ]);

        self::assertSame('MX', $mexico->snapshot()['primary_country']);
        self::assertSame('MXN', $mexico->defaultCurrency());
        self::assertSame('es-MX', $mexico->defaultLocale());
    }

    public function testScopeSupportsSingleMultiAndGlobalWithoutChangingIdentity(): void
    {
        $single = CondorMarketDefaults::initial();
        $multi = MarketScope::fromArray([
            'mode' => 'multi_country',
            'primary_country' => 'CO',
            'target_countries' => ['CO', 'MX'],
            'excluded_countries' => [],
            'launch_countries' => ['CO'],
            'expansion_candidates' => ['MX'],
            'default_currency' => 'COP',
            'default_locale' => 'es-CO',
        ]);
        $global = MarketScope::fromArray([
            'mode' => 'global',
            'primary_country' => 'CO',
            'target_countries' => ['CO'],
            'excluded_countries' => [],
            'launch_countries' => ['CO'],
            'expansion_candidates' => ['MX', 'US'],
            'default_currency' => 'COP',
            'default_locale' => 'es-CO',
        ]);

        self::assertSame('single_country', $single->mode());
        self::assertSame('multi_country', $multi->mode());
        self::assertSame('global', $global->mode());
        self::assertSame('CO', $single->snapshot()['primary_country']);
        self::assertSame('CO', $multi->snapshot()['primary_country']);
        self::assertSame('CO', $global->snapshot()['primary_country']);
    }

    public function testGlobalDoesNotImplicitlyAuthorizeCountries(): void
    {
        $scope = MarketScope::fromArray([
            'mode' => 'global',
            'primary_country' => null,
            'target_countries' => [],
            'excluded_countries' => [],
            'launch_countries' => [],
            'expansion_candidates' => ['MX'],
            'default_currency' => 'USD',
            'default_locale' => 'en-US',
        ]);

        self::assertFalse($scope->isExplicitTarget('US'));
        self::assertFalse($scope->isLaunchAuthorized('US'));
        self::assertFalse($scope->isLaunchAuthorized('MX'));
    }

    public function testAddingCountryDoesNotChangeCoreSemantics(): void
    {
        $first = MarketScope::fromArray([
            'mode' => 'multi_country',
            'primary_country' => 'CO',
            'target_countries' => ['CO', 'MX'],
            'excluded_countries' => [],
            'launch_countries' => ['CO'],
            'expansion_candidates' => ['MX'],
            'default_currency' => 'COP',
            'default_locale' => 'es-CO',
        ]);
        $expanded = MarketScope::fromArray([
            'mode' => 'multi_country',
            'primary_country' => 'CO',
            'target_countries' => ['CL', 'CO', 'MX'],
            'excluded_countries' => [],
            'launch_countries' => ['CO'],
            'expansion_candidates' => ['CL', 'MX'],
            'default_currency' => 'COP',
            'default_locale' => 'es-CO',
        ]);

        self::assertSame(array_keys($first->snapshot()), array_keys($expanded->snapshot()));
        self::assertSame('multi_country', $expanded->mode());
        self::assertFalse($expanded->isLaunchAuthorized('CL'));
    }

    public function testLaunchCountriesMustBeExplicitTargets(): void
    {
        $this->expectException(DomainException::class);

        MarketScope::fromArray([
            'mode' => 'global',
            'primary_country' => null,
            'target_countries' => [],
            'excluded_countries' => [],
            'launch_countries' => ['US'],
            'expansion_candidates' => [],
            'default_currency' => 'USD',
            'default_locale' => 'en-US',
        ]);
    }
}
