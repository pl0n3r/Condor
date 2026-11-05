<?php

declare(strict_types=1);

namespace App\Application\Onboarding;

use App\Application\Commercial\PlanQuoteService;
use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Commercial\Entity\PublicTrialEnrollment;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Commercial\Entity\SubscriptionConfiguration;
use App\Domain\Commercial\SubscriptionLifecycle;
use App\Domain\Commercial\SubscriptionState;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Infrastructure\Notification\MailerFactory;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * Public sign-up, deliberately inactive without the explicit feature flag.
 * Requesting only stores a normalized email and a token hash; tenant details
 * and passwords are accepted only after the mailbox link is presented.
 */
final readonly class PublicTrialEnrollmentService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProvisionTenant $provisionTenant,
        private PlanQuoteService $quotes,
        private PublicTrialCaptcha $captcha,
        private MailerFactory $mailerFactory,
        private UserPasswordHasherInterface $passwordHasher,
        #[Autowire('%app.canonical_url%')]
        private string $canonicalUrl,
    ) {
    }

    public function enabled(): bool
    {
        // This feature must not become public through an accidental deploy.
        return getenv('CONDOR_PUBLIC_TRIAL_ENABLED') === '1'
            && preg_match('#^https://(?:www\.)?condorapp\.com\.co/?$#D', $this->canonicalUrl) === 1;
    }

    /** @param array<string,mixed> $payload */
    public function request(array $payload): void
    {
        $this->assertEnabled();
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== ['captcha', 'consent', 'email']
            || ($payload['consent'] ?? null) !== true
            || !is_string($payload['email'] ?? null)
            || !is_string($payload['captcha'] ?? null)
        ) {
            throw new DomainException('Solicitud de trial inválida.');
        }
        $email = strtolower(trim($payload['email']));
        if (strlen($email) > 180 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('Solicitud de trial inválida.');
        }
        if (!$this->captcha->verify($payload['captcha'])) {
            throw new DomainException('Verificación antiautomatización no disponible.');
        }
        [$dsn, $from] = $this->mailConfiguration();

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        try {
            $this->entityManager->wrapInTransaction(function () use (
                $email, $now, $tokenHash, $token, $dsn, $from,
            ): void {
                // Same public response for previously registered addresses.
                if ($this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]) instanceof User) {
                    return;
                }
                $record = $this->entityManager
                    ->getRepository(PublicTrialEnrollment::class)
                    ->findOneBy(['email' => $email]);
                if ($record instanceof PublicTrialEnrollment) {
                    $this->entityManager->lock($record, LockMode::PESSIMISTIC_WRITE);
                    if (!$record->canReissue($now)) {
                        return;
                    }
                    $record->reissue($tokenHash, $now);
                } else {
                    $this->entityManager->persist(new PublicTrialEnrollment($email, $tokenHash, $now));
                }
                $this->entityManager->flush();

                // Failure rolls back the pending token; never log the bearer URL.
                try {
                    $this->mailerFactory->create($dsn)->send(
                        (new Email())
                            ->from($from)
                            ->to($email)
                            ->subject('Verifica tu correo para iniciar el trial de Condor')
                            ->text(
                                "Confirma tu dirección de correo desde este enlace:\n"
                                .rtrim($this->canonicalUrl, '/').'/trial/verificar/'.$token
                                ."\n\nEl enlace caduca en 30 minutos y se utiliza una sola vez."
                                ."\nSi no solicitaste el trial, ignora este mensaje."
                            ),
                    );
                } catch (Throwable) {
                    throw new RuntimeException('El correo de verificación no está disponible.');
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Concurrent submissions of the same email are indistinguishable.
            return;
        }
    }

    public function canVerify(string $token): bool
    {
        if (!$this->enabled() || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return false;
        }
        $record = $this->entityManager->getRepository(PublicTrialEnrollment::class)
            ->findOneBy(['tokenHash' => hash('sha256', $token)]);

        return $record instanceof PublicTrialEnrollment
            && $record->canConsume(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /**
     * @param array<string,mixed> $payload
     * @return bool true only for the first successful activation
     */
    public function verify(string $token, array $payload): bool
    {
        $this->assertEnabled();
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return false;
        }

        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== [
            'branch_name', 'legal_name', 'nit', 'owner_name',
            'owner_password', 'tenant_name', 'tenant_slug',
        ]) {
            throw new DomainException('Formulario de activación inválido.');
        }
        foreach ($payload as $key => $value) {
            if (!is_string($value) || strlen($value) > ($key === 'owner_password' ? 4096 : 180)) {
                throw new DomainException('Formulario de activación inválido.');
            }
        }
        if (strlen($payload['owner_password']) < 12 || trim($payload['owner_password']) === '') {
            throw new DomainException('La contraseña debe tener 12 caracteres o más.');
        }
        $data = [];
        foreach ($payload as $key => $value) {
            $data[$key] = $key === 'owner_password' ? $value : trim($value);
        }
        $input = new ProvisionTenantInput(
            $data['tenant_name'], $data['tenant_slug'], $data['legal_name'],
            $data['nit'] === '' ? null : $data['nit'], $data['branch_name'],
        );
        $hash = hash('sha256', $token);
        try {
            return $this->entityManager->wrapInTransaction(function () use ($hash, $input, $data): bool {
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $record = $this->entityManager->getRepository(PublicTrialEnrollment::class)
                    ->findOneBy(['tokenHash' => $hash]);
                if (!$record instanceof PublicTrialEnrollment) {
                    return false;
                }
                $this->entityManager->lock($record, LockMode::PESSIMISTIC_WRITE);
                if (!$record->canConsume($now)
                    || $this->entityManager->getRepository(User::class)->findOneBy(['email' => $record->email()]) instanceof User
                ) {
                    return false;
                }

                // All writes, including authoritative quote and trial, share ONE transaction.
                $provision = $this->provisionTenant->prepare($input);
                $user = new User($record->email(), $data['owner_name']);
                $user->setPasswordHash($this->passwordHasher->hashPassword($user, $data['owner_password']));
                $membership = new Membership($provision->tenant, $user, Membership::ROLE_OWNER);
                $quote = $this->quotes->preview('business', 'commerce', 'monthly', [], [], $now);
                if ($quote->planVersion()->plan()->key() !== 'business') {
                    throw new DomainException('Catálogo comercial no disponible.');
                }
                $lifecycle = new SubscriptionLifecycle(
                    $provision->tenant->id(), $quote->planVersion(), SubscriptionState::Trialing, $now,
                );
                $subscription = Subscription::fromLifecycle($lifecycle, $now);
                $configuration = new SubscriptionConfiguration(
                    $subscription, $quote->vertical(), $quote->quantities(), [], $now,
                );
                foreach ([$user, $membership, $quote, $subscription, $configuration] as $entity) {
                    $this->entityManager->persist($entity);
                }
                $quote->convert();
                $this->entityManager->persist(new AuditEvent(
                    $provision->tenant,
                    $user->id(),
                    'tenant.public_trial_activated',
                    PublicTrialEnrollment::class,
                    $provision->tenant->id(),
                    ['quote_id' => $quote->id(), 'duration_days' => SubscriptionLifecycle::TRIAL_DURATION_DAYS],
                ));
                $record->markConsumed($provision->tenant->id(), $now);
                $this->entityManager->flush();

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false; // concurrent email or tenant slug wins without duplicate provisioning
        } catch (ValidationFailedException) {
            throw new DomainException('Datos de empresa inválidos.');
        }
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled()) {
            throw new RuntimeException('El autoservicio de trial no está habilitado.');
        }
    }

    /** @return array{string,string} */
    private function mailConfiguration(): array
    {
        $dsn = getenv('CONDOR_MAILER_DSN');
        $from = getenv('CONDOR_MAIL_FROM');
        if (!is_string($dsn) || preg_match('#^smtps?://#i', $dsn) !== 1
            || !is_string($from) || filter_var($from, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new RuntimeException('El correo de verificación no está configurado.');
        }

        return [$dsn, $from];
    }
}
