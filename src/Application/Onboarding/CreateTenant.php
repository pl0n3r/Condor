<?php

declare(strict_types=1);

namespace App\Application\Onboarding;

use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class CreateTenant
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ValidatorInterface $validator,
    ) {
    }

    public function execute(CreateTenantInput $input): OnboardingResult
    {
        $violations = $this->validator->validate($input);
        if (count($violations) > 0) {
            throw new ValidationFailedException($input, $violations);
        }

        $slug = strtolower(trim($input->tenantSlug));
        $email = strtolower(trim($input->ownerEmail));

        if ($this->entityManager->getRepository(Tenant::class)->findOneBy(['slug' => $slug]) !== null) {
            throw new DomainException('Ya existe una empresa con ese identificador.');
        }

        if ($this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]) !== null) {
            throw new DomainException('Ya existe una cuenta con ese correo.');
        }

        try {
            return $this->entityManager->wrapInTransaction(function () use ($input, $slug, $email): OnboardingResult {
                $tenant = new Tenant($input->tenantName, $slug);
                $legalEntity = new LegalEntity($tenant, $input->legalName, $input->nit, true);
                $branch = new Branch($tenant, $input->branchName, 'principal', $legalEntity, true);
                $owner = new User($email, $input->ownerName);
                $owner->setPasswordHash($this->passwordHasher->hashPassword($owner, $input->ownerPassword));
                $membership = new Membership($tenant, $owner, Membership::ROLE_OWNER);

                foreach ([$tenant, $legalEntity, $branch, $owner, $membership] as $entity) {
                    $this->entityManager->persist($entity);
                }

                $this->entityManager->persist(new AuditEvent(
                    $tenant,
                    $owner->id(),
                    'tenant.onboarding_completed',
                    Tenant::class,
                    $tenant->id(),
                    [
                        'branch_id' => $branch->id(),
                        'legal_entity_id' => $legalEntity->id(),
                    ],
                ));

                $this->entityManager->flush();

                return new OnboardingResult($tenant->id(), $tenant->slug(), $branch->id(), $owner->id());
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new DomainException(
                'Los datos de la empresa ya están registrados (identificador o correo).',
                0,
                $exception,
            );
        }
    }
}
