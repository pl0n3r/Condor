<?php

declare(strict_types=1);

namespace App\Application\Commercial;

use App\Domain\Commercial\Entity\AddOn;
use App\Domain\Commercial\Entity\Capability;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\Entity\Vertical;
use App\Domain\Commercial\Entity\VerticalCapability;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

final readonly class CommercialCatalogSeeder
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function seed(): void
    {
        $this->entityManager->wrapInTransaction(function (): void {
            $this->materialize();
        });
    }

    private function materialize(): void
    {
        $plans = $this->identities(Plan::class, [
            'basic' => 'Básico',
            'business' => 'Negocio',
            'pro' => 'Pro',
            'enterprise' => 'Enterprise',
        ]);
        $verticals = $this->identities(Vertical::class, [
            'commerce' => 'Comercio / distribución / ecommerce',
            'textile' => 'Textil / moda / confección',
            'manufacturing' => 'Manufactura ligera',
            'professional-services' => 'Servicios profesionales',
            'legal' => 'Legal / abogados',
        ]);
        $capabilities = $this->identities(Capability::class, [
            'catalog' => 'Catálogo',
            'inventory' => 'Inventario / kardex',
            'contacts' => 'Clientes y proveedores',
            'purchasing' => 'Compras',
            'sales-orders' => 'Ventas y pedidos',
            'pricing' => 'Precios',
            'transfers' => 'Transferencias',
            'custom-domain' => 'Dominio propio',
            'reports' => 'Reportes operativos',
            'manufacturing' => 'Producción completa',
            'api-webhooks' => 'API y webhooks',
            'advanced-permissions' => 'Permisos avanzados',
            'advanced-analytics' => 'Analítica avanzada',
            'multi-company' => 'Multiempresa',
            'legal-cases' => 'Casos / expedientes',
            'documents' => 'Documentos',
            'calendar-deadlines' => 'Calendario y plazos',
        ]);
        $addOns = $this->addOns();
        $this->verticalCapabilities($verticals, $capabilities);

        $effectiveFrom = new DateTimeImmutable('2026-09-27T00:00:00Z', new DateTimeZone('UTC'));
        $definitions = [
            'basic' => [79900, 799000, false, ['companies'=>1,'users'=>3,'locations'=>1]],
            'business' => [199900, 1999000, false, ['companies'=>1,'users'=>10,'locations'=>3]],
            'pro' => [499900, 4999000, false, ['companies'=>5,'users'=>30,'locations'=>10]],
            'enterprise' => [null, null, true, []],
        ];

        foreach ($definitions as $key => [$monthly, $annual, $quoteRequired, $limits]) {
            $version = $this->version($plans[$key], $monthly, $annual, $quoteRequired, $limits, $effectiveFrom);
            foreach ($verticals as $vertical) {
                $version->addVertical($vertical);
            }
            foreach ($this->capabilitiesFor($key, $capabilities) as $capability) {
                $version->addCapability($capability);
            }
            foreach ($this->addOnsFor($key, $addOns) as $addOn) {
                $version->addAddOn($addOn);
            }
        }

        $this->entityManager->flush();
        $this->assertCanonicalCatalogPersisted();
    }

    private function assertCanonicalCatalogPersisted(): void
    {
        $expected = [
            Plan::class => ['basic', 'business', 'pro', 'enterprise'],
            Vertical::class => [
                'commerce',
                'textile',
                'manufacturing',
                'professional-services',
                'legal',
            ],
            Capability::class => [
                'catalog', 'inventory', 'contacts', 'purchasing',
                'sales-orders', 'pricing', 'transfers', 'custom-domain',
                'reports', 'manufacturing', 'api-webhooks',
                'advanced-permissions', 'advanced-analytics',
                'multi-company', 'legal-cases', 'documents',
                'calendar-deadlines',
            ],
            AddOn::class => [
                'extra-user', 'extra-location', 'extra-company',
                'extra-store', 'production-lite', 'premium-integration',
            ],
        ];

        foreach ($expected as $class => $keys) {
            $repository = $this->entityManager->getRepository($class);
            foreach ($keys as $key) {
                if (!$repository->findOneBy(['key' => $key]) instanceof $class) {
                    throw new RuntimeException(
                        sprintf('Falta la clave comercial canónica %s.', $key),
                    );
                }
            }
        }

        $versions = $this->entityManager->getRepository(PlanVersion::class);
        foreach (['basic', 'business', 'pro', 'enterprise'] as $key) {
            $plan = $this->entityManager->getRepository(Plan::class)
                ->findOneBy(['key' => $key]);
            $version = $plan instanceof Plan
                ? $versions->findOneBy(['plan' => $plan, 'version' => 1])
                : null;
            if (
                !($plan instanceof Plan)
                || !($version instanceof PlanVersion)
            ) {
                throw new RuntimeException(
                    sprintf('Falta PlanVersion v1 para %s.', $key),
                );
            }
        }
    }

    /**
     * @template T of Plan|Vertical|Capability
     * @param class-string<T> $class
     * @param array<string,string> $definitions
     * @return array<string,T>
     */
    private function identities(string $class, array $definitions): array
    {
        $repository = $this->entityManager->getRepository($class);
        $result = [];
        foreach ($definitions as $key => $name) {
            $entity = $repository->findOneBy(['key' => $key]);
            if (!$entity instanceof $class) {
                $entity = new $class($key, $name);
                $this->entityManager->persist($entity);
            }
            $result[$key] = $entity;
        }
        return $result;
    }

    /** @return array<string,AddOn> */
    private function addOns(): array
    {
        $definitions = [
            'extra-user' => ['Usuario adicional', 14900, false],
            'extra-location' => ['Sede adicional', 29900, false],
            'extra-company' => ['Empresa adicional', 79900, false],
            'extra-store' => ['Tienda / marca adicional', 49900, false],
            'production-lite' => ['Producción Lite', 99900, false],
            'premium-integration' => ['Integración premium', 49900, false],
        ];
        $repository = $this->entityManager->getRepository(AddOn::class);
        $result = [];
        foreach ($definitions as $key => [$name, $monthly, $quoteRequired]) {
            $entity = $repository->findOneBy(['key' => $key]);
            if (!$entity instanceof AddOn) {
                $entity = new AddOn($key, $name, $monthly, $quoteRequired);
                $this->entityManager->persist($entity);
            }
            $result[$key] = $entity;
        }
        return $result;
    }

    /**
     * @param array<string,Vertical> $verticals
     * @param array<string,Capability> $capabilities
     */
    private function verticalCapabilities(array $verticals, array $capabilities): void
    {
        $definitions = [
            'commerce' => [
                'catalog', 'contacts', 'sales-orders', 'pricing', 'inventory',
                'purchasing', 'transfers', 'custom-domain', 'reports',
                'api-webhooks', 'advanced-permissions', 'advanced-analytics',
                'multi-company',
            ],
            'textile' => [
                'catalog', 'contacts', 'sales-orders', 'pricing', 'inventory',
                'purchasing', 'transfers', 'manufacturing', 'custom-domain',
                'reports', 'api-webhooks', 'advanced-permissions',
                'advanced-analytics', 'multi-company',
            ],
            'manufacturing' => [
                'inventory', 'purchasing', 'manufacturing', 'catalog',
                'contacts', 'sales-orders', 'pricing', 'transfers', 'reports',
                'api-webhooks', 'advanced-permissions', 'advanced-analytics',
                'multi-company',
            ],
            'professional-services' => [
                'contacts', 'sales-orders', 'pricing', 'documents',
                'calendar-deadlines', 'custom-domain', 'reports',
                'api-webhooks', 'advanced-permissions', 'advanced-analytics',
                'multi-company',
            ],
            'legal' => [
                'contacts', 'legal-cases', 'documents', 'calendar-deadlines',
                'reports', 'advanced-permissions', 'api-webhooks',
                'custom-domain', 'advanced-analytics', 'multi-company',
            ],
        ];

        $repository = $this->entityManager->getRepository(VerticalCapability::class);
        foreach ($definitions as $verticalKey => $capabilityKeys) {
            foreach ($capabilityKeys as $offset => $capabilityKey) {
                $vertical = $verticals[$verticalKey];
                $capability = $capabilities[$capabilityKey];
                $relationKey = VerticalCapability::keyFor($vertical, $capability);
                $priority = $offset + 1;
                $relation = $repository->findOneBy(['key' => $relationKey]);
                if (!$relation instanceof VerticalCapability) {
                    $relation = new VerticalCapability($vertical, $capability, $priority);
                    $this->entityManager->persist($relation);
                    continue;
                }
                $relation->reorder($priority);
            }
        }
    }

    /** @param array<string,bool|int|string|null> $limits */
    private function version(
        Plan $plan,
        ?int $monthly,
        ?int $annual,
        bool $quoteRequired,
        array $limits,
        DateTimeImmutable $effectiveFrom,
    ): PlanVersion {
        $version = $this->entityManager->getRepository(PlanVersion::class)->findOneBy([
            'plan' => $plan,
            'version' => 1,
        ]);
        if ($version instanceof PlanVersion) {
            return $version;
        }

        $version = new PlanVersion(
            $plan, 1, $monthly, $annual, $quoteRequired, $limits, $effectiveFrom,
        );
        $this->entityManager->persist($version);
        return $version;
    }

    /**
     * @param array<string,Capability> $all
     * @return list<Capability>
     */
    private function capabilitiesFor(string $planKey, array $all): array
    {
        $keys = match ($planKey) {
            'basic' => ['catalog','inventory','contacts','purchasing','sales-orders','pricing','legal-cases','documents','calendar-deadlines'],
            'business' => ['catalog','inventory','contacts','purchasing','sales-orders','pricing','transfers','custom-domain','reports','legal-cases','documents','calendar-deadlines'],
            'pro', 'enterprise' => array_keys($all),
            default => [],
        };
        return array_values(array_intersect_key($all, array_flip($keys)));
    }

    /**
     * @param array<string,AddOn> $all
     * @return list<AddOn>
     */
    private function addOnsFor(string $planKey, array $all): array
    {
        $keys = match ($planKey) {
            'basic' => ['extra-user','extra-location'],
            'business' => ['extra-user','extra-location','extra-company','extra-store','production-lite','premium-integration'],
            'pro' => ['extra-user','extra-location','extra-company','extra-store','premium-integration'],
            'enterprise' => ['premium-integration'],
            default => [],
        };
        return array_values(array_intersect_key($all, array_flip($keys)));
    }
}
