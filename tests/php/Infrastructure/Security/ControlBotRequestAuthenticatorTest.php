<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security;

use App\Infrastructure\Security\ControlBotCanonicalRequest;
use App\Infrastructure\Security\ControlBotRequestAuthenticator;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ControlBotRequestAuthenticatorTest extends KernelTestCase
{
    private const TEST_KEY = 'synthetic-controlbot-authenticator-key-32bytes';

    private Connection $connection;
    private string $keyId;
    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['CONDOR_CONTROLBOT_KEY_ID', 'CONDOR_CONTROLBOT_KEY', 'CONDOR_CONTROLBOT_ALLOWED_IPS'] as $name) {
            $this->previousEnvironment[$name] = getenv($name);
        }
        $this->keyId = 'qa-'.bin2hex(random_bytes(8));
        putenv('CONDOR_CONTROLBOT_KEY_ID='.$this->keyId);
        putenv('CONDOR_CONTROLBOT_KEY='.self::TEST_KEY);
        putenv('CONDOR_CONTROLBOT_ALLOWED_IPS=127.0.0.1');

        self::bootKernel();
        $db = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->connection = $db;
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->connection)) {
                $this->connection->executeStatement(
                    'DELETE FROM condor_controlbot_nonce WHERE key_id = :key_id',
                    ['key_id' => $this->keyId],
                );
            }
        } finally {
            foreach ($this->previousEnvironment as $name => $value) {
                putenv($value === false ? $name : $name.'='.$value);
            }
            parent::tearDown();
        }
    }

    private function authenticator(): ControlBotRequestAuthenticator
    {
        $limiter = new RateLimiterFactory(
            ['id' => 'condor-qa-'.$this->keyId, 'policy' => 'fixed_window', 'limit' => 60, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );
        return new ControlBotRequestAuthenticator($this->connection, $limiter);
    }

    private function signedRequest(?int $timestamp = null, ?string $nonce = null): Request
    {
        $request = Request::create('https://www.condorapp.com.co/ops/summary', 'GET');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $timestampText = (string) ($timestamp ?? time());
        $nonce ??= bin2hex(random_bytes(18));
        $request->headers->set('X-Factory-Key-Id', $this->keyId);
        $request->headers->set('X-Factory-Timestamp', $timestampText);
        $request->headers->set('X-Factory-Nonce', $nonce);
        $request->headers->set('X-Factory-Signature', hash_hmac('sha256',
            ControlBotCanonicalRequest::canonical($request, $this->keyId, $timestampText, $nonce),
            self::TEST_KEY,
        ));
        return $request;
    }

    private function claimedNonces(): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM condor_controlbot_nonce WHERE key_id = :key_id',
            ['key_id' => $this->keyId],
        );
    }

    public function testValidSignatureClaimsNonceOnceAndReplayIsRejected(): void
    {
        $authenticator = $this->authenticator();
        $request = $this->signedRequest();
        $authenticator->authenticate($request);
        self::assertSame(1, $this->claimedNonces());

        try {
            $authenticator->authenticate($request);
            self::fail('Replaying the same nonce must fail closed.');
        } catch (ConflictHttpException) {
            self::assertSame(1, $this->claimedNonces());
        }
    }

    public function testModifiedHmacIsRejectedBeforeNonceInsert(): void
    {
        $request = $this->signedRequest();
        $request->headers->set('X-Factory-Signature', str_repeat('0', 64));
        try {
            $this->authenticator()->authenticate($request);
            self::fail('A tampered signature must be rejected.');
        } catch (UnauthorizedHttpException) {
            self::assertSame(0, $this->claimedNonces());
        }
    }

    public function testOutOfWindowPastAndFutureTimestampsFailClosed(): void
    {
        foreach ([time() - 301, time() + 301] as $timestamp) {
            try {
                $this->authenticator()->authenticate($this->signedRequest($timestamp));
                self::fail('An out-of-window timestamp must be rejected.');
            } catch (UnauthorizedHttpException) {
                self::assertSame(0, $this->claimedNonces());
            }
        }
    }

    public function testMalformedNonceFailsClosed(): void
    {
        try {
            $this->authenticator()->authenticate($this->signedRequest(nonce: 'short'));
            self::fail('Short nonces are not authentic requests.');
        } catch (UnauthorizedHttpException) {
            self::assertSame(0, $this->claimedNonces());
        }
    }

    public function testInsecureHttpFailsBeforeNonceInsert(): void
    {
        $request = $this->signedRequest();
        $request->server->set('HTTPS', 'off');
        $request->server->set('SERVER_PORT', '80');
        try {
            $this->authenticator()->authenticate($request);
            self::fail('ControlBot must reject plain HTTP.');
        } catch (AccessDeniedHttpException) {
            self::assertSame(0, $this->claimedNonces());
        }
    }

    public function testUntrustedSourceIpFailsBeforeNonceInsert(): void
    {
        $request = $this->signedRequest();
        $request->server->set('REMOTE_ADDR', '192.0.2.34');
        try {
            $this->authenticator()->authenticate($request);
            self::fail('Unlisted IPs must be denied.');
        } catch (AccessDeniedHttpException) {
            self::assertSame(0, $this->claimedNonces());
        }
    }

    public function testMissingConfigurationReturns404WithoutClaim(): void
    {
        putenv('CONDOR_CONTROLBOT_KEY');
        try {
            $this->authenticator()->authenticate($this->signedRequest());
            self::fail('Unconfigured M2M credentials must expose no endpoint.');
        } catch (NotFoundHttpException) {
            self::assertSame(0, $this->claimedNonces());
        }
    }
}
