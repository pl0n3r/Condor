<?php

declare(strict_types=1);

namespace App\Tests\Application\Storefront;

use App\Application\Storefront\StorefrontPresentation;
use App\Domain\Organization\Entity\StorefrontProfile;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\TenantDomain;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class StorefrontPresentationTest extends TestCase
{
    public function testMissingProfileOrVerifiedPrimaryFailsClosedToCondor(): void
    {
        $tenant = new Tenant('  Empresa sintética  ', 'Prueba-Tienda');
        $unverified = new TenantDomain($tenant, 'pendiente.example.test', true, false);
        $presentation = $this->presentationFor($tenant, null, [], [$unverified]);

        self::assertSame([
            'name' => 'Empresa sintética',
            'slug' => 'prueba-tienda',
            'headline' => '',
            'description' => '',
            'canonical' => 'https://www.condorapp.com.co/prueba-tienda',
        ], $presentation->publicIdentity($tenant));
        self::assertSame([[
            'host' => 'pendiente.example.test',
            'primary' => true,
            'verified' => false,
            'status' => 'Pendiente de verificación',
        ]], $presentation->registeredDomains($tenant));
    }

    public function testVerifiedPrimaryAndPublicProfileAreRenderedWithoutTrustingHtml(): void
    {
        $tenant = new Tenant('Empresa pública', 'empresa-publica');
        $profile = new StorefrontProfile($tenant, '<script>alert(1)</script>', '  <b>Descripción</b>  ');
        $verified = new TenantDomain($tenant, 'ventas.example.test.', true, true);
        $secondary = new TenantDomain($tenant, 'otros.example.test', false, false);
        $presentation = $this->presentationFor($tenant, $profile, [$verified], [$secondary, $verified]);

        $identity = $presentation->publicIdentity($tenant);
        self::assertSame('https://ventas.example.test/', $identity['canonical']);
        self::assertSame('<script>alert(1)</script>', $identity['headline']);
        self::assertSame('<b>Descripción</b>', $identity['description']);
        // Presentation returns untrusted content as data. Twig must escape at the output boundary.
        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt;',
            htmlspecialchars($identity['headline'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        self::assertSame([
            ['host' => 'otros.example.test', 'primary' => false, 'verified' => false,
                'status' => 'Pendiente de verificación'],
            ['host' => 'ventas.example.test', 'primary' => true, 'verified' => true,
                'status' => 'Verificado en Condor; DNS y TLS requieren comprobación operativa'],
        ], $presentation->registeredDomains($tenant));
    }

    public function testAmbiguousVerifiedPrimariesNeverClaimAExternalCanonical(): void
    {
        $tenant = new Tenant('Empresa antigua', 'empresa-antigua');
        // Synthetic corrupt legacy state: the database constraint normally prevents this.
        $first = new TenantDomain($tenant, 'uno.example.test', true, true);
        $second = new TenantDomain($tenant, 'dos.example.test', true, true);
        $presentation = $this->presentationFor($tenant, null, [$first, $second], [$first, $second]);

        self::assertSame('https://www.condorapp.com.co/empresa-antigua',
            $presentation->publicIdentity($tenant)['canonical']);
        self::assertCount(2, $presentation->registeredDomains($tenant));
    }

    public function testQueriesAreScopedByExactTenant(): void
    {
        $tenant = new Tenant('Empresa uno', 'empresa-uno');
        $other = new Tenant('Empresa dos', 'empresa-dos');
        $otherProfile = new StorefrontProfile($other, 'Dato ajeno', 'Privado');
        $otherDomain = new TenantDomain($other, 'ajeno.example.test', true, true);
        $presentation = $this->presentationFor($tenant, $otherProfile, [$otherDomain], [$otherDomain]);

        $result = $presentation->publicIdentity($tenant);
        self::assertSame('', $result['headline']);
        self::assertSame('https://www.condorapp.com.co/empresa-uno', $result['canonical']);
        self::assertSame([], $presentation->registeredDomains($tenant));
    }

    /** @param list<TenantDomain> $primaries @param list<TenantDomain> $domains */
    private function presentationFor(
        Tenant $tenant,
        ?StorefrontProfile $profile,
        array $primaries,
        array $domains,
    ): StorefrontPresentation {
        $profiles = $this->createMock(EntityRepository::class);
        $profiles->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?StorefrontProfile =>
                ($criteria['tenant'] ?? null) === $tenant && $profile?->tenant() === $tenant
                    ? $profile : null,
        );
        $domainRepository = $this->createMock(EntityRepository::class);
        $domainRepository->method('findBy')->willReturnCallback(
            static function (array $criteria, ?array $orderBy = null, ?int $limit = null) use ($tenant, $primaries, $domains): array {
                if (($criteria['tenant'] ?? null) !== $tenant) {
                    return [];
                }
                $filtered = isset($criteria['primary'], $criteria['verified'])
                    ? array_filter($primaries, static fn (TenantDomain $domain): bool =>
                        $domain->tenant() === $tenant && $domain->isPrimary() && $domain->isVerified())
                    : array_filter($domains, static fn (TenantDomain $domain): bool => $domain->tenant() === $tenant);
                $filtered = array_values($filtered);
                if ($orderBy === ['hostname' => 'ASC']) {
                    usort($filtered, static fn (TenantDomain $a, TenantDomain $b): int =>
                        strcmp($a->hostname(), $b->hostname()));
                }
                return $limit !== null ? array_slice($filtered, 0, $limit) : $filtered;
            },
        );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $class): EntityRepository => match ($class) {
                StorefrontProfile::class => $profiles,
                TenantDomain::class => $domainRepository,
                default => throw new \LogicException('Unexpected repository: '.$class),
            },
        );
        return new StorefrontPresentation($entityManager);
    }
}
