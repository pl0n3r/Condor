<?php

declare(strict_types=1);

namespace App\Tests\Application\Identity;

use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use App\Infrastructure\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class CurrentTenantForUserTest extends KernelTestCase
{
    public function testItRejectsAUserFromAnotherTenantContext(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $context = $container->get(TenantContext::class);
        $resolver = $container->get(CurrentTenantForUser::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(TenantContext::class, $context);
        self::assertInstanceOf(CurrentTenantForUser::class, $resolver);

        $suffix = strtolower(bin2hex(random_bytes(4)));
        $allowed = new Tenant('Permitida', 'permitida-'.$suffix);
        $forbidden = new Tenant('No permitida', 'no-permitida-'.$suffix);
        $user = new User('user-'.$suffix.'@example.test', 'Usuario');
        $membership = new Membership($allowed, $user, Membership::ROLE_OWNER);

        $entityManager->persist($allowed);
        $entityManager->persist($forbidden);
        $entityManager->persist($user);
        $entityManager->persist($membership);
        $entityManager->flush();

        $context->reset();
        $context->set($forbidden);

        $this->expectException(AccessDeniedException::class);
        $resolver->resolve($user);
    }

    public function testItDoesNotChooseArbitrarilyBetweenMultipleMemberships(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $context = $container->get(TenantContext::class);
        $resolver = $container->get(CurrentTenantForUser::class);

        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(TenantContext::class, $context);
        self::assertInstanceOf(CurrentTenantForUser::class, $resolver);

        $suffix = strtolower(bin2hex(random_bytes(4)));
        $first = new Tenant('Empresa Uno', 'empresa-uno-'.$suffix);
        $second = new Tenant('Empresa Dos', 'empresa-dos-'.$suffix);
        $user = new User('multi-'.$suffix.'@example.test', 'Usuario Multi');

        $entityManager->persist($first);
        $entityManager->persist($second);
        $entityManager->persist($user);
        $entityManager->persist(new Membership($first, $user, Membership::ROLE_OWNER));
        $entityManager->persist(new Membership($second, $user, 'READ_ONLY'));
        $entityManager->flush();

        $context->reset();

        $this->expectException(AccessDeniedException::class);
        $resolver->resolve($user);
    }
}
