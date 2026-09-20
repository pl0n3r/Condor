<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Tenancy;

use App\Domain\Organization\Entity\Tenant;
use App\Domain\Organization\Entity\TenantDomain;
use App\Infrastructure\Tenancy\TenantResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

final class TenantResolverTest extends KernelTestCase
{
    public function testItResolvesVerifiedDomainsWithoutCrossingTenants(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $resolver = $container->get(TenantResolver::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(TenantResolver::class, $resolver);

        $suffix = strtolower(bin2hex(random_bytes(4)));
        $first = new Tenant('Empresa A', 'empresa-a-'.$suffix);
        $second = new Tenant('Empresa B', 'empresa-b-'.$suffix);

        $entityManager->persist($first);
        $entityManager->persist($second);
        $entityManager->persist(new TenantDomain($first, 'a-'.$suffix.'.example.test', true, true));
        $entityManager->persist(new TenantDomain($second, 'b-'.$suffix.'.example.test', true, true));
        $entityManager->persist(new TenantDomain($second, 'no-'.$suffix.'.example.test', false, false));
        $entityManager->flush();

        $resolvedFirst = $resolver->resolve(Request::create('https://a-'.$suffix.'.example.test/'));
        $resolvedSecond = $resolver->resolve(Request::create('https://b-'.$suffix.'.example.test/'));
        $unverified = $resolver->resolve(Request::create('https://no-'.$suffix.'.example.test/'));

        $crossedRequest = Request::create('https://a-'.$suffix.'.example.test/'.$second->slug());
        $crossedRequest->attributes->set('tenant_slug', $second->slug());
        $crossed = $resolver->resolve($crossedRequest);

        $platformRequest = Request::create('https://condorapp.com.co/'.$second->slug());
        $platformRequest->attributes->set('tenant_slug', $second->slug());
        $platformResolved = $resolver->resolve($platformRequest);

        self::assertSame($first->id(), $resolvedFirst?->id());
        self::assertSame($second->id(), $resolvedSecond?->id());
        self::assertNull($unverified);
        self::assertSame($first->id(), $crossed?->id());
        self::assertSame($second->id(), $platformResolved?->id());
        self::assertNotSame($resolvedFirst?->id(), $resolvedSecond?->id());
    }
}
