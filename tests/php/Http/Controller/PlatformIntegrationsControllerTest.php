<?php

declare(strict_types=1);

namespace App\Tests\Http\Controller;

use App\Application\Platform\PlatformIntegrationSettings;
use App\Domain\Identity\Entity\User;
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

        // El constructor de Sentry lee el DSN almacenado: no activar en fase
        // construcción. La autorización aquí es solo sintética y temporal.
        $client->jsonRequest('PUT', self::PATH, [
            'key' => 'sentry.dsn', 'value' => 'https://synthetic.invalid/9876',
        ], ['HTTP_X_CSRF_TOKEN' => $data['csrf_token']]);
        self::assertResponseStatusCodeSame(423);

        $oldPhase = getenv('CONDOR_PHASE');
        $oldWrite = getenv('CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED');
        putenv('CONDOR_PHASE=live');
        putenv('CONDOR_PLATFORM_INTEGRATIONS_SECRET_WRITE_ENABLED=true');
        $synthetic = 'https://fixture_secret_'.bin2hex(random_bytes(8)).'@sentry.synthetic.invalid/9876';
        try {
            $client->jsonRequest('PUT', self::PATH, [
                'key' => 'sentry.dsn', 'value' => $synthetic,
            ], ['HTTP_X_CSRF_TOKEN' => $data['csrf_token']]);
            self::assertResponseStatusCodeSame(200);
            $body = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($synthetic, $body);
            self::assertStringNotContainsString('fixture_secret_', $body);
            $safe = self::responseData($body);
            self::assertTrue($safe['setting']['configured']);
            self::assertSame('••••9876', $safe['setting']['last4']);
            self::assertArrayNotHasKey('value', $safe['setting']);
            self::assertNotEmpty($safe['setting']['updated_at']);
            self::assertSame('private, no-store', $client->getResponse()->headers->get('Cache-Control'));

            $client->request('GET', self::PATH);
            self::assertResponseStatusCodeSame(200);
            $redacted = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($synthetic, $redacted);
            self::assertSame('••••9876', self::responseData($redacted)['settings']['sentry.dsn']['last4']);

            $client->request('DELETE', self::PATH.'/secret/sentry.dsn', server: [
                'HTTP_X_CSRF_TOKEN' => $data['csrf_token'],
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
        putenv('CONDOR_PHASE=construccion');
        putenv('CONDOR_PLATFORM_TEST_EMAIL_ENABLED=false');
        try {
            $client = self::createClient();
            $owner = $this->actor(true);
            $client->loginUser($owner);
            $client->request('GET', self::PATH);
            $token = self::responseData((string) $client->getResponse()->getContent())['csrf_token'];
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
        }
    }
}
