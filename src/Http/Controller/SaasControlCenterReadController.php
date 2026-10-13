<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Commercial\SaasControlCenterReader;
use App\Application\Identity\PlatformOwnerTenantContext;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\Entity\UsageObservation;
use App\Domain\Commercial\UsageLedger;
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

        $reader = new SaasControlCenterReader(
            fn (string $id): ?array => $this->catalog($id),
            fn (string $id): array => $this->usage($id),
            fn (string $id): array => $this->entitlements($id),
        );

        try {
            $projection = $reader->read(
                User::ROLE_PLATFORM_OWNER,
                $tenantId,
                $tenantId, // Tenant identity was independently resolved above.
            );
        } catch (DomainException) {
            // Never serialize adapter exceptions, raw tenant rows or SQL errors.
            return $this->json(
                ['state' => 'error', 'message' => 'Datos comerciales no disponibles.'],
                JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return $this->json($projection);
    }

    /** @return array<string,mixed>|null */
    private function catalog(string $tenantId): ?array
    {
        $subscription = $this->subscription($tenantId);
        if ($subscription === null) {
            return null;
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
                'active' => true,
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
    private function usage(string $tenantId): array
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
            if (!$record instanceof UsageObservation) {
                throw new DomainException('Registro de uso inválido.');
            }
            $ledger->add($record->toRecord());
        }

        $limits = $subscription->planVersion()->limits();
        $result = [];
        foreach ($ledger->snapshot() as $row) {
            $metric = $row['metric'];
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
    private function entitlements(string $tenantId): array
    {
        $subscription = $this->subscription($tenantId);
        if ($subscription === null) {
            return [];
        }

        $items = [];
        foreach ($subscription->planVersion()->capabilities() as $capability) {
            $items[] = [
                'tenant_id' => $tenantId,
                'key' => $capability->key(),
                'allowed' => true,
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
