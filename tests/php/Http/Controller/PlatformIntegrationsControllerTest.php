<?php

declare(strict_types=1);

namespace App\Tests\Http\Controller;

use App\Application\Platform\PlatformIntegrationSettings;
use App\Infrastructure\Observability\SentryTestEventSender;
use App\Domain\Identity\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformIntegrationsControllerTest extends WebTestCase
{
    private const PATH = '/adminpl0n3r/api/integrations';

    private function actor(bool $owner): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $user = new User(
            'integrations-'.($owner ? 'owner' : 'tenant').'-'.bin2hex(random_bytes(6)).'@example.test',
            'QA integraciones',
            $owner ? [User::ROLE_PLATFORM_OWNER] : [],
        );
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** @return array<string,mixed> */
    private static function responseData(string $body): array
    {
        $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        return $result;
    }

    public function testOnlyOwnerCanListSaveAndDeleteWithValidCsrf(): void
    {
        $client = self::createClient();
        $client->request('GET', self::PATH);
        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);

        $owner = $this->actor(true);
        $tenant = $this->actor(false);
        $client->loginUser($tenant);
        $client->request('GET', self::PATH);
        self::assertResponseStatusCodeSame(403);

        $client->jsonRequest('PUT', self::PATH, ['key' => 'sentry.dsn', 'value' => 'https://fake.invalid/8765']);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($owner);
        $client->request('GET', self::PATH);
        self::assertResponseStatusCodeSame(200);
        $data = self::responseData((string) $client->getResponse()->getContent());
        self::assertArrayHasKey('csrf_token', $data);
        self::assertFalse($data['test_email_enabled']);
        self::assertFalse($data['secret_write_enabled']);
        self::assertSame(['mail.provider', 'mail.from', 'mail.api_key', 'sentry.dsn'],
            array_keys($data['settings']));
        self::assertArrayNotHasKey('value', $data['settings']['sentry.dsn']);

        $client->jsonRequest('PUT', self::PATH, ['key' => 'sentry.dsn', 'value' => 'https://fake.invalid/8765']);
        self::assertResponseStatusCodeSame(403);

        // La fase construcción no permite guardar secretos sin el flag del dueño.
        // Estos datos sintéticos nunca deben activar un proveedor externo.
        $client->jsonRequest('PUT', self::PATH, [
            'key' => 'sentry.dsn', 'value' => 'https://synthetic.invalid/9876',
        ], ['HTTP_X_CSRF_TOKEN' => $data['csrf_token']]);
        self::assertResponseStatusCodeSame(423);

        $oldPhase = getenv('CONDOR_PHASE');
        $oldWrite = getenv('CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED');
        $oldTestMail = getenv('CONDOR_PLATFORM_TEST_EMAIL_ENABLED');
        putenv('CONDOR_PHASE=construccion');
        putenv('CONDOR_PLATFORM_TEST_EMAIL_ENABLED=false');
        putenv('CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED=true');
        $synthetic = 'https://fixture_secret_'.bin2hex(random_bytes(8)).'@sentry.synthetic.invalid/9876';
        try {
            $client->request('GET', self::PATH);
            self::assertResponseStatusCodeSame(200);
            $enabled = self::responseData((string) $client->getResponse()->getContent());
            self::assertTrue($enabled['secret_write_enabled']);
            self::assertFalse($enabled['test_email_enabled']);

            $client->loginUser($tenant);
            $client->jsonRequest('PUT', self::PATH, [
                'key' => 'sentry.dsn', 'value' => $synthetic,
            ], ['HTTP_X_CSRF_TOKEN' => $enabled['csrf_token']]);
            self::assertResponseStatusCodeSame(403);

            $client->loginUser($owner);
            $client->jsonRequest('PUT', self::PATH, [
                'key' => 'sentry.dsn', 'value' => $synthetic,
            ]);
            self::assertResponseStatusCodeSame(403);
            $client->request('GET', self::PATH);
            $ownerToken = self::responseData((string) $client->getResponse()->getContent())['csrf_token'];

            $client->jsonRequest('PUT', self::PATH, [
                'key' => 'sentry.dsn', 'value' => $synthetic,
            ], ['HTTP_X_CSRF_TOKEN' => $ownerToken]);
            self::assertResponseStatusCodeSame(200);
            $body = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($synthetic, $body);
            self::assertStringNotContainsString('fixture_secret_', $body);
            $safe = self::responseData($body);
            self::assertTrue($safe['setting']['configured']);
            self::assertSame('••••9876', $safe['setting']['last4']);
            self::assertArrayNotHasKey('value', $safe['setting']);
            self::assertNotEmpty($safe['setting']['updated_at']);
            $connection = self::getContainer()->get(Connection::class);
            self::assertInstanceOf(Connection::class, $connection);
            $stored = $connection->fetchAssociative(
                'SELECT plain_value, encrypted_value, nonce, key_version '
                .'FROM condor_platform_integration_setting WHERE setting_key = ?',
                ['sentry.dsn'],
            );
            self::assertIsArray($stored);
            self::assertNull($stored['plain_value']);
            self::assertNotEmpty($stored['encrypted_value']);
            self::assertNotEmpty($stored['nonce']);
            self::assertSame(1, (int) $stored['key_version']);
            self::assertStringNotContainsString($synthetic, (string) $stored['encrypted_value']);
            $audit = $connection->fetchAssociative(
                'SELECT action, actor_id FROM condor_platform_setting_audit '
                .'WHERE setting_key = ? ORDER BY id DESC LIMIT 1',
                ['sentry.dsn'],
            );
            self::assertIsArray($audit);
            self::assertSame('UPSERT', $audit['action']);
            self::assertSame($owner->id(), $audit['actor_id']);
            self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('private'));
            self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));

            $client->request('GET', self::PATH);
            self::assertResponseStatusCodeSame(200);
            $redacted = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($synthetic, $redacted);
            self::assertSame('••••9876', self::responseData($redacted)['settings']['sentry.dsn']['last4']);

            $client->request('DELETE', self::PATH.'/secret/sentry.dsn', server: [
                'HTTP_X_CSRF_TOKEN' => $ownerToken,
            ]);
            self::assertResponseStatusCodeSame(200);
            self::assertFalse(self::responseData(
                (string) $client->getResponse()->getContent(),
            )['setting']['configured']);
        } finally {
            // Sin credenciales ni datos reales: se limpia únicamente el ajuste sintético.
            $settings = self::getContainer()->get(PlatformIntegrationSettings::class);
            self::assertInstanceOf(PlatformIntegrationSettings::class, $settings);
            $settings->delete('sentry.dsn', $owner->id(), true);
            putenv($oldPhase === false ? 'CONDOR_PHASE' : 'CONDOR_PHASE='.$oldPhase);
            putenv($oldWrite === false
                ? 'CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED'
                : 'CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED='.$oldWrite);
            putenv($oldTestMail === false
                ? 'CONDOR_PLATFORM_TEST_EMAIL_ENABLED'
                : 'CONDOR_PLATFORM_TEST_EMAIL_ENABLED='.$oldTestMail);
        }
    }

    public function testInvalidKeyAndSecretNeverEchoInErrors(): void
    {
        $client = self::createClient();
        $owner = $this->actor(true);
        $client->loginUser($owner);
        $client->request('GET', self::PATH);
        $token = self::responseData((string) $client->getResponse()->getContent())['csrf_token'];

        $synthetic = 'fixture-sensitive-token-forbidden';
        $client->jsonRequest('PUT', self::PATH, [
            'key' => 'other.secret', 'value' => $synthetic,
        ], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringNotContainsString($synthetic, (string) $client->getResponse()->getContent());

        $client->jsonRequest('PUT', self::PATH, [
            'key' => 'mail.api_key', 'value' => 'short',
        ], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(423);
        self::assertStringNotContainsString('short', (string) $client->getResponse()->getContent());
    }

    public function testRealMailIsDisabledAndLimitedToOneRequestPerMinute(): void
    {
        $previousPhase = getenv('CONDOR_PHASE');
        $previousFlag = getenv('CONDOR_PLATFORM_TEST_EMAIL_ENABLED');
        $previousWrite = getenv('CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED');
        putenv('CONDOR_PHASE=construccion');
        putenv('CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED=true');
        putenv('CONDOR_PLATFORM_TEST_EMAIL_ENABLED=false');
        try {
            $client = self::createClient();
            $owner = $this->actor(true);
            $client->loginUser($owner);
            $client->request('GET', self::PATH);
            $snapshot = self::responseData((string) $client->getResponse()->getContent());
            self::assertTrue($snapshot['secret_write_enabled']);
            self::assertFalse($snapshot['test_email_enabled']);
            $token = $snapshot['csrf_token'];
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $client->jsonRequest('POST', self::PATH.'/test-email', [
                    'email' => 'destino-sintetico@example.test',
                ], ['HTTP_X_CSRF_TOKEN' => $token]);
                self::assertResponseStatusCodeSame($attempt === 0 ? 423 : 429);
            }
        } finally {
            putenv($previousPhase === false ? 'CONDOR_PHASE' : 'CONDOR_PHASE='.$previousPhase);
            putenv($previousFlag === false
                ? 'CONDOR_PLATFORM_TEST_EMAIL_ENABLED'
                : 'CONDOR_PLATFORM_TEST_EMAIL_ENABLED='.$previousFlag);
            putenv($previousWrite === false
                ? 'CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED'
                : 'CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED='.$previousWrite);
        }
    }

    public function testSentrySyntheticEventRequiresOwnerCsrfDsnAndOnePerMinute(): void
    {
        $previousWrite = getenv('CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED');
        $previousSentry = getenv('CONDOR_PLATFORM_SENTRY_TEST_ENABLED');
        putenv('CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED=true');
        putenv('CONDOR_PLATFORM_SENTRY_TEST_ENABLED=true');
        $owner = null;
        try {
            $client = self::createClient();
            $client->disableReboot(); // Preserve synthetic sender across requests.
            $stub = $this->createMock(SentryTestEventSender::class);
            $stub->expects(self::once())->method('send')
                ->willReturn('0123456789abcdef0123456789abcdef');
            self::getContainer()->set(SentryTestEventSender::class, $stub);
            $owner = $this->actor(true);
            $other = $this->actor(false);
            $client->loginUser($owner);
            $client->request('GET', self::PATH);
            $data = self::responseData((string) $client->getResponse()->getContent());
            self::assertTrue($data['sentry_test_enabled']);
            self::assertFalse($data['sentry_capture_active']); // kernel test, not prod
            $token = $data['csrf_token'];

            $client->jsonRequest('POST', self::PATH.'/test-sentry', []);
            self::assertResponseStatusCodeSame(403);

            $client->loginUser($other);
            $client->jsonRequest('POST', self::PATH.'/test-sentry', [], [
                'HTTP_X_CSRF_TOKEN' => $token,
            ]);
            self::assertResponseStatusCodeSame(403);

            $client->loginUser($owner);
            $client->jsonRequest('POST', self::PATH.'/test-sentry', [], [
                'HTTP_X_CSRF_TOKEN' => $token,
            ]);
            self::assertResponseStatusCodeSame(409);
            self::assertStringContainsString(
                'DSN de Sentry ausente',
                (string) $client->getResponse()->getContent(),
            );

            $dsn = 'https://fixture_secret_'.bin2hex(random_bytes(8)).'@sentry.synthetic.invalid/42';
            $client->jsonRequest('PUT', self::PATH, ['key' => 'sentry.dsn', 'value' => $dsn], [
                'HTTP_X_CSRF_TOKEN' => $token,
            ]);
            self::assertResponseStatusCodeSame(200);

            $client->jsonRequest('POST', self::PATH.'/test-sentry', [], [
                'HTTP_X_CSRF_TOKEN' => $token,
            ]);
            self::assertResponseStatusCodeSame(200);
            $response = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($dsn, $response);
            self::assertStringNotContainsString('fixture_secret_', $response);
            $sent = self::responseData($response);
            self::assertSame('0123456789abcdef0123456789abcdef', $sent['event_id']);
            self::assertTrue($sent['sent']);

            $client->jsonRequest('POST', self::PATH.'/test-sentry', [], [
                'HTTP_X_CSRF_TOKEN' => $token,
            ]);
            self::assertResponseStatusCodeSame(429);
            self::assertStringNotContainsString($dsn, (string) $client->getResponse()->getContent());
        } finally {
            if ($owner !== null) {
                $settings = self::getContainer()->get(PlatformIntegrationSettings::class);
                self::assertInstanceOf(PlatformIntegrationSettings::class, $settings);
                $settings->delete('sentry.dsn', $owner->id(), true);
            }
            putenv($previousWrite === false
                ? 'CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED'
                : 'CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED='.$previousWrite);
            putenv($previousSentry === false
                ? 'CONDOR_PLATFORM_SENTRY_TEST_ENABLED'
                : 'CONDOR_PLATFORM_SENTRY_TEST_ENABLED='.$previousSentry);
        }
    }

    public function testSentryFailureIsFixedCodeAndNeverEchoesDsn(): void
    {
        $previousWrite = getenv('CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED');
        $previousSentry = getenv('CONDOR_PLATFORM_SENTRY_TEST_ENABLED');
        putenv('CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED=true');
        putenv('CONDOR_PLATFORM_SENTRY_TEST_ENABLED=true');
        $owner = null;
        try {
            $client = self::createClient();
            $client->disableReboot(); // Preserve synthetic sender across requests.
            $sender = $this->createMock(SentryTestEventSender::class);
            $sender->method('send')->willThrowException(new \DomainException('rejected'));
            self::getContainer()->set(SentryTestEventSender::class, $sender);
            $owner = $this->actor(true);
            $client->loginUser($owner);
            $client->request('GET', self::PATH);
            $token = self::responseData((string) $client->getResponse()->getContent())['csrf_token'];
            $dsn = 'https://fixture_secret_'.bin2hex(random_bytes(8)).'@sentry.synthetic.invalid/43';
            $client->jsonRequest('PUT', self::PATH, ['key' => 'sentry.dsn', 'value' => $dsn], [
                'HTTP_X_CSRF_TOKEN' => $token,
            ]);
            self::assertResponseStatusCodeSame(200);
            $client->jsonRequest('POST', self::PATH.'/test-sentry', [], [
                'HTTP_X_CSRF_TOKEN' => $token,
            ]);
            self::assertResponseStatusCodeSame(502);
            $response = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Sentry rechazó', $response);
            self::assertStringNotContainsString($dsn, $response);
            self::assertStringNotContainsString('fixture_secret_', $response);
        } finally {
            if ($owner !== null) {
                $settings = self::getContainer()->get(PlatformIntegrationSettings::class);
                self::assertInstanceOf(PlatformIntegrationSettings::class, $settings);
                $settings->delete('sentry.dsn', $owner->id(), true);
            }
            putenv($previousWrite === false
                ? 'CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED'
                : 'CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED='.$previousWrite);
            putenv($previousSentry === false
                ? 'CONDOR_PLATFORM_SENTRY_TEST_ENABLED'
                : 'CONDOR_PLATFORM_SENTRY_TEST_ENABLED='.$previousSentry);
        }
    }

    public function testSentryFlagIsFailClosed(): void
    {
        $previousWrite = getenv('CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED');
        $previousFlag = getenv('CONDOR_PLATFORM_SENTRY_TEST_ENABLED');
        putenv('CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED=true');
        putenv('CONDOR_PLATFORM_SENTRY_TEST_ENABLED=false');
        try {
            $client = self::createClient();
            $client->loginUser($this->actor(true));
            $client->request('GET', self::PATH);
            $settings = self::responseData((string) $client->getResponse()->getContent());
            self::assertFalse($settings['sentry_test_enabled']);
            $client->jsonRequest('POST', self::PATH.'/test-sentry', [], [
                'HTTP_X_CSRF_TOKEN' => $settings['csrf_token'],
            ]);
            self::assertResponseStatusCodeSame(423);
        } finally {
            putenv($previousWrite === false
                ? 'CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED'
                : 'CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED='.$previousWrite);
            putenv($previousFlag === false
                ? 'CONDOR_PLATFORM_SENTRY_TEST_ENABLED'
                : 'CONDOR_PLATFORM_SENTRY_TEST_ENABLED='.$previousFlag);
        }
    }

}
