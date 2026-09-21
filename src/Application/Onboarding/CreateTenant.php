<?php

declare(strict_types=1);

namespace App\Application\Onboarding;

use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
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
        private ProvisionTenant $provisionTenant,
    ) {
    }

    public function execute(CreateTenantInput $input): OnboardingResult
    {
        $violations = $this->validator->validate($input);
        if (count($violations) > 0) {
            throw new ValidationFailedException($input, $violations);
        }

        $email = strtolower(trim($input->ownerEmail));
        if (
            $this->entityManager
                ->getRepository(User::class)
                ->findOneBy(['email' => $email]) instanceof User
        ) {
            throw new DomainException(
                'Ya existe una cuenta con ese correo.',
            );
        }

        try {
            return $this->entityManager->wrapInTransaction(
                function () use ($input, $email): OnboardingResult {
                    $provisioning = $this->provisionTenant->prepare(
                        new ProvisionTenantInput(
                            $input->tenantName,
                            $input->tenantSlug,
                            $input->legalName,
                            $input->nit,
                            $input->branchName,
                        ),
                    );

                    $owner = new User($email, $input->ownerName);
                    $owner->setPasswordHash(
                        $this->passwordHasher->hashPassword(
                            $owner,
                            $input->ownerPassword,
                        ),
                    );
                    $membership = new Membership(
                        $provisioning->tenant,
                        $owner,
                        Membership::ROLE_OWNER,
                    );

                    foreach ([$owner, $membership] as $entity) {
                        $this->entityManager->persist($entity);
                    }

                    $this->entityManager->persist(new AuditEvent(
                        $provisioning->tenant,
                        $owner->id(),
                        'tenant.onboarding_completed',
                        $provisioning->tenant::class,
                        $provisioning->tenant->id(),
                        [
                            'branch_id' => $provisioning->branch->id(),
                            'legal_entity_id' => (
                                $provisioning->legalEntity->id()
                            ),
                        ],
                    ));

                    $this->entityManager->flush();

                    return new OnboardingResult(
                        $provisioning->tenant->id(),
                        $provisioning->tenant->slug(),
                        $provisioning->branch->id(),
                        $owner->id(),
                    );
                },
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new DomainException(
                'Los datos de la empresa ya están registrados '
                .'(identificador o correo).',
                0,
                $exception,
            );
        }
    }
}
