<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\Entity\SubscriptionConfiguration;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use App\Infrastructure\Persistence\ModuleAccessRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Authority for one tenant and authenticated user. Explicit owner overrides
 * take precedence over plan defaults; user grants never resurrect a disabled
 * tenant module. Role/branch permissions still apply independently.
 */
final class ModuleAccess
{
    /** @var array<string, array<string, bool>> */
    private array $tenantCache = [];

    /** @var array<string, array<string, bool>> */
    private array $userCache = [];

    /** @var array<string, array<string, bool>> */
    private array $planCache = [];

    public function __construct(
        private readonly ModuleAccessRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly OwnerSupportSession $support,
    ) {
    }

    public function canAccess(User $user, Tenant $tenant, string $module): bool
    {
        if (!$user->isActive() || !ModuleCatalog::exists($module)) {
            return false;
        }
        if ($user->hasRole(User::ROLE_PLATFORM_OWNER)) {
            $support = $this->support->current($user);
            if ($support !== null && $support['tenant']->id() === $tenant->id()) {
                return true;
            }
        }

        $tenantId = $tenant->id();
        $tenantOverrides = $this->tenantOverrides($tenantId);
        $planDefault = $this->planDefaults($tenantId)[$module] ?? true;
        if (!($tenantOverrides[$module] ?? $planDefault)) {
            return false;
        }

        return $this->userOverrides($tenantId, $user->id())[$module] ?? true;
    }

    /**
     * Only enforce the additional owner switches at the HTTP boundary.
     * Existing commercial entitlement guards retain their established 422
     * response when a plan/add-on is unavailable. An explicit owner/user
     * disable is always a 403, before any controller can mutate data.
     */
    public function disabledByOwner(User $user, Tenant $tenant, string $module): bool
    {
        if (!$user->isActive() || !ModuleCatalog::exists($module)) {
            return true;
        }
        if ($user->hasRole(User::ROLE_PLATFORM_OWNER)) {
            $support = $this->support->current($user);
            if ($support !== null && $support['tenant']->id() === $tenant->id()) {
                return false;
            }
        }
        if (($this->tenantOverrides($tenant->id())[$module] ?? null) === false) {
            return true;
        }

        return ($this->userOverrides($tenant->id(), $user->id())[$module] ?? null) === false;
    }

    /** @return list<string> */
    public function allowed(User $user, Tenant $tenant): array
    {
        $result = [];
        foreach (ModuleCatalog::all() as $item) {
            if ($this->canAccess($user, $tenant, $item['key'])) {
                $result[] = $item['key'];
            }
        }

        return $result;
    }

    /** @return array<string, bool> */
    public function tenantOverrides(string $tenantId): array
    {
        return $this->tenantCache[$tenantId] ??= $this->repository->tenantOverrides($tenantId);
    }

    /** @return array<string, bool> */
    public function userOverrides(string $tenantId, string $userId): array
    {
        $key = $tenantId.':'.$userId;

        return $this->userCache[$key] ??= $this->repository->userOverrides($tenantId, $userId);
    }

    /** @return array<string, bool> */
    public function planDefaults(string $tenantId): array
    {
        if (isset($this->planCache[$tenantId])) {
            return $this->planCache[$tenantId];
        }

        $subscription = $this->entityManager->getRepository(Subscription::class)
            ->findOneBy(['tenantId' => $tenantId]);
        $capabilities = [];
        $productionLite = false;
        if ($subscription instanceof Subscription) {
            foreach ($subscription->planVersion()->capabilities() as $capability) {
                $capabilities[$capability->key()] = true;
            }
            // Existing production APIs are entitled by the production-lite
            // add-on, not by the manufacturing capability alone. Respect the
            // actual subscribed configuration before an owner override.
            $configuration = $this->entityManager->getRepository(SubscriptionConfiguration::class)
                ->findOneBy(['subscription' => $subscription]);
            if ($configuration instanceof SubscriptionConfiguration) {
                foreach ($configuration->addOns() as $addOn) {
                    if ($addOn->key() === 'production-lite') {
                        $productionLite = true;
                    }
                }
            }
        }

        $defaults = [];
        foreach (ModuleCatalog::all() as $item) {
            $capability = $item['capability'];
            // No subscription / no corresponding commercial capability:
            // preserve the legacy behavior, never silently hide a module.
            $defaults[$item['key']] = $item['key'] === 'production' && $subscription instanceof Subscription
                ? $productionLite
                : ($capability === null || $subscription === null || isset($capabilities[$capability]));
        }

        return $this->planCache[$tenantId] = $defaults;
    }

    public function invalidate(string $tenantId, ?string $userId = null): void
    {
        unset($this->tenantCache[$tenantId]);
        if ($userId !== null) {
            unset($this->userCache[$tenantId.':'.$userId]);
        }
    }
}
