<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Application\Commercial\CommercialCatalogSeeder;
use App\Application\Commercial\PlanQuoteService;
use App\Application\Onboarding\ProvisionTenant;
use App\Application\Onboarding\PublicTrialCaptcha;
use App\Application\Onboarding\PublicTrialEnrollmentService;
use App\Domain\Commercial\Entity\PublicTrialEnrollment;
use App\Domain\Commercial\Entity\Quote;
use App\Domain\Commercial\Entity\Subscription;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Tenant;
use App\Infrastructure\Notification\MailerFactory;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PublicTrialEnrollmentHttpTest extends WebTestCase
{
    protected function tearDown(): void
    {
        foreach (['CONDOR_PUBLIC_TRIAL_ENABLED', 'CONDOR_MAILER_DSN', 'CONDOR_MAIL_FROM',
            'CONDOR_TRIAL_CAPTCHA_SECRET', 'CONDOR_TRIAL_CAPTCHA_HOSTNAME'] as $key) {
            putenv($key);
        }
        parent::tearDown();
    }

    public function testPublicTrialIsDisabledByDefault(): void
    {
        putenv('CONDOR_PUBLIC_TRIAL_ENABLED=0');
        $client = self::createClient();
        $client->jsonRequest('POST', '/trial/solicitar', [
            'email' => 'anonymous@example.test', 'captcha' => 'unused', 'consent' => true,
        ]);
        self::assertResponseStatusCodeSame(503);
        self::assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testCaptchaMissingConfigurationFailsClosed(): void
    {
        putenv('CONDOR_PUBLIC_TRIAL_ENABLED=1');
        putenv('CONDOR_TRIAL_CAPTCHA_SECRET=');
        $client = self::createClient();
        $client->jsonRequest('POST', '/trial/solicitar', [
            'email' => 'captcha@example.test', 'captcha' => 'invalid-captcha-token', 'consent' => true,
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testVerifiedEmailProvisionsSingleFourteenDayTrialAtomically(): void
    {
        [$client, $mailer] = $this->readyClient();
        $email = 'trial-'.bin2hex(random_bytes(5)).'@example.test';
        $request = ['email' => strtoupper($email), 'captcha' => 'test-passed-captcha', 'consent' => true];
        $client->jsonRequest('POST', '/trial/solicitar', $request);
        self::assertResponseStatusCodeSame(202);
        self::assertCount(1, $mailer->messages);
        self::assertInstanceOf(Email::class, $mailer->messages[0]);
        self::assertSame($email, $mailer->messages[0]->getTo()[0]->getAddress());
        $body = (string) $mailer->messages[0]->getTextBody();
        self::assertMatchesRegularExpression('~/trial/verificar/[a-f0-9]{64}~', $body);
        preg_match('~/trial/verificar/([a-f0-9]{64})~', $body, $match);
        $token = $match[1];
        $url = '/trial/verificar/'.$token;
        self::assertSame(1, $this->em()->getRepository(PublicTrialEnrollment::class)->count(['email' => $email]));

        // Duplicate pending email does not send another link or reveal account status.
        $client->jsonRequest('POST', '/trial/solicitar', $request);
        self::assertResponseStatusCodeSame(202);
        self::assertCount(1, $mailer->messages);
        $client->jsonRequest('POST', '/trial/solicitar', $request + ['tenantId' => 'spoofed']);
        self::assertResponseStatusCodeSame(422);

        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->em()->getRepository(Subscription::class)->count([]));
        self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
        preg_match('/name="_csrf_token" value="([^"]+)"/', (string) $client->getResponse()->getContent(), $csrfMatch);
        self::assertCount(2, $csrfMatch);

        $fields = [
            'tenant_name' => 'Empresa verificada',
            'tenant_slug' => 'empresa-trial-'.bin2hex(random_bytes(5)),
            'legal_name' => 'Empresa verificada SAS',
            'nit' => '',
            'branch_name' => 'Sede Principal',
            'owner_name' => 'Dueño de prueba',
            'owner_password' => 'clave-muy-larga-segura-123',
            '_csrf_token' => html_entity_decode($csrfMatch[1], ENT_QUOTES, 'UTF-8'),
        ];
        $client->request('POST', $url, $fields + ['plan_version_id' => 'spoofed']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em()->getRepository(Subscription::class)->count([]));

        $client->request('POST', $url, $fields);
        self::assertResponseStatusCodeSame(303);
        self::assertSame('/admin/login', $client->getResponse()->headers->get('Location'));
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['slug' => $fields['tenant_slug']]);
        self::assertInstanceOf(Tenant::class, $tenant);
        $owner = $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $owner);
        self::assertNotSame('', $owner->getPassword());
        $membership = $this->em()->getRepository(Membership::class)->findOneBy(['tenant' => $tenant, 'user' => $owner]);
        self::assertInstanceOf(Membership::class, $membership);
        self::assertSame(Membership::ROLE_OWNER, $membership->roleKey());
        $subscription = $this->em()->getRepository(Subscription::class)->findOneBy(['tenantId' => $tenant->id()]);
        self::assertInstanceOf(Subscription::class, $subscription);
        self::assertSame('trialing', $subscription->state()->value);
        $trial = $subscription->toLifecycle();
        self::assertSame(
            $trial->trialStartedAt()?->add(new DateInterval('P14D'))->format('U.u'),
            $trial->trialEndsAt()?->format('U.u'),
        );
        self::assertGreaterThanOrEqual(1, $this->em()->getRepository(Quote::class)->count(['status' => 'converted']));

        // The unique email ledger survives consumption; the bearer is destroyed.
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(410);
        $client->request('POST', $url, $fields);
        self::assertResponseStatusCodeSame(410);
        $client->jsonRequest('POST', '/trial/solicitar', $request);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(1, $this->em()->getRepository(Subscription::class)->count(['tenantId' => $tenant->id()]));
        self::assertCount(1, $mailer->messages);
    }

    public function testInvalidInputAndCsrfDoNotProvisionAnything(): void
    {
        [$client, $mailer] = $this->readyClient();
        $email = 'trial-invalid-'.bin2hex(random_bytes(5)).'@example.test';
        $client->jsonRequest('POST', '/trial/solicitar', [
            'email' => $email, 'captcha' => 'test-passed-captcha', 'consent' => true,
        ]);
        self::assertResponseStatusCodeSame(202);
        preg_match('~/trial/verificar/([a-f0-9]{64})~', (string) $mailer->messages[0]->getTextBody(), $match);
        $url = '/trial/verificar/'.$match[1];
        $client->request('POST', $url, ['tenant_name' => 'Empresa']);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->em()->getRepository(User::class)->count(['email' => $email]));
    }

    /** @return array{\Symfony\Bundle\FrameworkBundle\KernelBrowser,CapturingTrialMailer} */
    private function readyClient(): array
    {
        putenv('CONDOR_PUBLIC_TRIAL_ENABLED=1');
        putenv('CONDOR_MAILER_DSN=smtp://example.test:25');
        putenv('CONDOR_MAIL_FROM=notificaciones@example.test');
        $client = self::createClient();
        $container = self::getContainer();
        $seeder = $container->get(CommercialCatalogSeeder::class);
        self::assertInstanceOf(CommercialCatalogSeeder::class, $seeder);
        $seeder->seed();
        $mailer = new CapturingTrialMailer();
        $container->set(PublicTrialEnrollmentService::class, new PublicTrialEnrollmentService(
            $this->em(),
            $container->get(ProvisionTenant::class),
            $container->get(PlanQuoteService::class),
            new PassingTrialCaptcha(),
            new CapturingTrialMailerFactory($mailer),
            $container->get(UserPasswordHasherInterface::class),
            'https://www.condorapp.com.co',
        ));

        return [$client, $mailer];
    }

    private function em(): EntityManagerInterface
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        return $manager;
    }
}

final class PassingTrialCaptcha extends PublicTrialCaptcha
{
    public function verify(string $response): bool
    {
        return $response === 'test-passed-captcha';
    }
}

final class CapturingTrialMailer implements MailerInterface
{
    /** @var list<RawMessage> */
    public array $messages = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->messages[] = $message;
    }
}

final readonly class CapturingTrialMailerFactory implements MailerFactory
{
    public function __construct(private CapturingTrialMailer $mailer)
    {
    }

    public function create(string $dsn): MailerInterface
    {
        return $this->mailer;
    }
}
