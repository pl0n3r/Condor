<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Commercial\SaasControlCenterReader;
use App\Application\Commercial\SubscriptionEntitlementContextFactory;
use App\Application\Commercial\EntitlementResolver;
use App\Application\Commercial\EntitlementSnapshot;
use DateTimeImmutable;
use DateTimeZone;
use App\Application\Identity\PlatformOwnerTenantContext;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\Entity\UsageObservation;
use App\Domain\Commercial\UsageLedger;
use App\Domain\Commercial\SubscriptionState;
use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only owner surface. A tenant is authenticated and resolved before any
 * commercial provider is invoked. All providers use tenant-scoped persisted data.
 */
final class SaasControlCenterReadController extends PlatformOwnerApiController
{
    public function __construct(
        private readonly PlatformOwnerTenantContext $tenants,
        private readonly EntityManagerInterface $entityManager,
        private readonly SubscriptionEntitlementContextFactory $entitlementContexts,
        private readonly EntitlementResolver $entitlementResolver,
    ) {
    }

    #[Route(
        '/adminpl0n3r/api/tenants/{tenantId}/saas-control-center',
        name: 'platform_owner_saas_control_center_read',
        methods: ['GET'],
    )]
    public function __invoke(string $tenantId): JsonResponse
    {
        $this->platformOwner();

        // Reject malformed and missing tenants before touching commercial ledgers.
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,79}\z/D', $tenantId) !== 1) {
            throw new NotFoundHttpException('Empresa no encontrada.');
        }
        $this->tenants->tenant($tenantId);

        // Resolve the same effective tenant entitlements for all three views.
        // A missing commercial configuration is UNKNOWN, never "allowed".
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $effective = null;
        try {
            $subscription = $this->subscription($tenantId);
            if ($subscription !== null) {
                // Configuration may outlive a suspended or cancelled contract;
                // never report its historical capabilities as active rights.
                if (in_array($subscription->state(), [
                    SubscriptionState::Suspended,
                    SubscriptionState::Cancelled,
                ], true)) {
                    return $this->unavailable();
                }
                $context = $this->entitlementContexts->forTenant($tenantId, $at);
                $effective = $this->entitlementResolver->resolve($context);
                if (!hash_equals($tenantId, $effective->tenantId())) {
                    throw new DomainException('Entitlement de otro tenant.');
                }
            }
        } catch (\Throwable) {
            // Auth and tenant scope were validated above; fail closed on any
            // provider/ORM fault without exposing technical diagnostics.
            return $this->unavailable();
        }

        $reader = new SaasControlCenterReader(
            fn (string $id): ?array => $this->catalog($id, $effective),
            fn (string $id): array => $this->usage($id, $effective, $at),
            fn (string $id): array => $this->entitlements($id, $effective),
        );

        try {
            $projection = $reader->read(
                User::ROLE_PLATFORM_OWNER,
                $tenantId,
                $tenantId, // Tenant identity was independently resolved above.
            );
        } catch (\Throwable) {
            // Never serialize adapter exceptions, raw tenant rows or SQL errors.
            return $this->unavailable();
        }

        return $this->privateReadJson($projection);
    }

    /** @return array<string,mixed>|null */
    private function catalog(string $tenantId, ?EntitlementSnapshot $effective): ?array
    {
        $subscription = $this->subscription($tenantId);
        if ($subscription === null) {
            return null;
        }

        if ($effective === null) {
            throw new DomainException('Entitlement no observado.');
        }
        $version = $subscription->planVersion();
        // A quote-required plan/add-on has no known price. Never encode it as 0.
        $monthly = $version->monthlyAmount();
        if ($monthly === null) {
            throw new DomainException('Precio todavía no observado.');
        }

        $addOns = [];
        foreach ($version->addOns() as $addOn) {
            $price = $addOn->monthlyAmount();
            if ($price === null) {
                throw new DomainException('Precio de add-on no observado.');
            }
            $addOns[] = [
                'tenant_id' => $tenantId,
                'key' => $addOn->key(),
                'price_minor' => self::priceMinor($price),
                'active' => $effective->addOn($addOn->key()),
            ];
        }

        return [
            'tenant_id' => $tenantId,
            'plan' => [
                'tenant_id' => $tenantId,
                'key' => $version->plan()->key(),
                'version' => (string) $version->version(),
                'currency' => $version->currency(),
                'price_minor' => self::priceMinor($monthly),
            ],
            'add_ons' => $addOns,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function usage(string $tenantId, ?EntitlementSnapshot $effective, DateTimeImmutable $at): array
    {
        $subscription = $this->subscription($tenantId);
        if ($subscription === null) {
            return [];
        }

        $ledger = new UsageLedger($tenantId);
        $records = $this->entityManager
            ->getRepository(UsageObservation::class)
            ->findBy(['tenantId' => $tenantId]);
        foreach ($records as $record) {
            $ledger->add($record->toRecord());
        }

        $limits = $effective?->limits();
        if ($limits === null) {
            throw new DomainException('Límites efectivos no observados.');
        }
        $result = [];
        $seen = [];
        foreach ($ledger->snapshot() as $row) {
            // Each usage metric must use its active time window only.
            // Mixing historic windows fabricates totals and can cause HTTP 503.
            $start = new DateTimeImmutable($row['window_start']);
            $end = new DateTimeImmutable($row['window_end']);
            if ($start > $at || $end <= $at) {
                continue;
            }
            $metric = $row['metric'];
            if (isset($seen[$metric])) {
                throw new DomainException('Ventanas de uso vigentes ambiguas.');
            }
            $seen[$metric] = true;
            $limit = $limits[$metric] ?? null;
            // A missing/non-numeric quota is UNKNOWN, not 0 or unlimited.
            if (!is_int($limit) || $limit < 0) {
                continue;
            }
            $result[] = [
                'tenant_id' => $tenantId,
                'metric' => $metric,
                'used' => $row['quantity'],
                'limit' => $limit,
            ];
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function entitlements(string $tenantId, ?EntitlementSnapshot $effective): array
    {
        $subscription = $this->subscription($tenantId);
        if ($subscription === null) {
            return [];
        }

        if ($effective === null) {
            throw new DomainException('Permisos efectivos no observados.');
        }
        $items = [];
        foreach ($subscription->planVersion()->capabilities() as $capability) {
            $items[] = [
                'tenant_id' => $tenantId,
                'key' => $capability->key(),
                'allowed' => $effective->capability($capability->key()),
            ];
        }

        return $items;
    }

    private function subscription(string $tenantId): ?Subscription
    {
        $subscription = $this->entityManager
            ->getRepository(Subscription::class)
            ->findOneBy(['tenantId' => $tenantId]);

        return $subscription instanceof Subscription ? $subscription : null;
    }

    /**
     * Tenant-scoped commercial rights must never remain in HTTP caches when
     * a subscription changes, a tenant is switched, or an owner logs out.
     *
     * @param array<string, mixed> $payload
     */
    private function privateReadJson(array $payload, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        $response = $this->json($payload, $status);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    private function unavailable(): JsonResponse
    {
        return $this->privateReadJson(
            ['state' => 'error', 'message' => 'Datos comerciales no disponibles.'],
            JsonResponse::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    private static function priceMinor(int $amount): int
    {
        // Canonical monthly amounts are in currency units (e.g. COP).
        // Guard the conversion to cents against JS Number precision loss.
        if ($amount < 0 || $amount > intdiv(9007199254740991, 100)) {
            throw new DomainException('Precio fuera de rango.');
        }

        return $amount * 100;
    }
}
