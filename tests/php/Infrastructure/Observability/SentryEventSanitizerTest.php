<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Observability;

use App\Infrastructure\Observability\SentryEventSanitizer;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Options;
use Sentry\Serializer\PayloadSerializer;
use Sentry\Severity;
use Sentry\Stacktrace;
use Sentry\UserDataBag;

final class SentryEventSanitizerTest extends TestCase
{
    public function testRemovesSensitiveRequestDataBeforeSending(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://www.condorapp.com.co/reset?token=secret&next=/admin',
            'method' => 'GET',
            'query_string' => 'token=secret&next=/admin',
            'cookies' => ['session' => 'secret'],
            'data' => ['password' => 'secret'],
            'headers' => [
                'Authorization' => ['Bearer secret'],
                'Cookie' => ['session=secret'],
                'Referer' => ['https://www.condorapp.com.co/login?token=secret'],
                'X-Real-IP' => ['203.0.113.10'],
                'X-Request-Id' => ['safe-id'],
            ],
        ]);
        $event->setUser(UserDataBag::createFromUserIpAddress('203.0.113.10'));

        $result = (new SentryEventSanitizer())($event);

        self::assertSame($event, $result);
        self::assertNull($event->getUser());

        $request = $event->getRequest();
        self::assertSame(
            'https://www.condorapp.com.co/reset',
            $request['url'],
        );
        self::assertArrayNotHasKey('query_string', $request);
        self::assertArrayNotHasKey('cookies', $request);
        self::assertArrayNotHasKey('data', $request);
        self::assertSame(['safe-id'], $request['headers']['X-Request-Id']);
        self::assertArrayNotHasKey('Authorization', $request['headers']);
        self::assertArrayNotHasKey('Cookie', $request['headers']);
        self::assertArrayNotHasKey('Referer', $request['headers']);
        self::assertArrayNotHasKey('X-Real-IP', $request['headers']);
    }

    public function testRemovesVarsFromEventAndExceptionStacktraces(): void
    {
        $eventFrame = new Frame(
            'eventHandler',
            '/srv/app/src/EventHandler.php',
            42,
            vars: ['token' => 'event-secret'],
        );
        $exceptionFrame = new Frame(
            'exceptionHandler',
            '/srv/app/src/ExceptionHandler.php',
            21,
            vars: ['password' => 'exception-secret'],
        );

        $event = Event::createEvent();
        $event->setStacktrace(new Stacktrace([$eventFrame]));
        $event->setExceptions([
            new ExceptionDataBag(
                new \RuntimeException('boom'),
                new Stacktrace([$exceptionFrame]),
            ),
        ]);

        (new SentryEventSanitizer())($event);

        self::assertSame([], $eventFrame->getVars());
        self::assertSame([], $exceptionFrame->getVars());
    }

    public function testLeavesQuerylessUrlStable(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://www.condorapp.com.co/health',
            'method' => 'GET',
        ]);

        (new SentryEventSanitizer())($event);

        self::assertSame(
            'https://www.condorapp.com.co/health',
            $event->getRequest()['url'],
        );
    }

    private const SECRETS = [
        'Sup3rS3cretPassw0rd',
        'ana.perez@example.test',
        'synthetic-api-key-7f3a9c1d5e',
        'ID-9876543210',
        'synthetic-session-8f14e45fcee',
    ];

    /** Lo que realmente viajaría a Sentry: el payload serializado por el propio SDK. */
    private function transmitted(Event $event): string
    {
        return (new PayloadSerializer(new Options()))->serialize($event);
    }

    private function assertNoSecretIsTransmitted(Event $event): void
    {
        $payload = $this->transmitted($event);
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $payload, 'filtrado: '.$secret);
            self::assertStringNotContainsString(rawurlencode($secret), $payload);
        }
    }

    public function testSensitiveExceptionAndEventMessageAreDenied(): void
    {
        $event = Event::createEvent();
        $event->setMessage('Fallo con password=%s para %s', [self::SECRETS[0], self::SECRETS[1]], 'Fallo con password='.self::SECRETS[0].' para '.self::SECRETS[1]);
        $event->setExceptions([
            new ExceptionDataBag(
                new \RuntimeException('API key '.self::SECRETS[2].' rechazada para '.self::SECRETS[1].' ('.self::SECRETS[3].')'),
                new Stacktrace([new Frame('handle', '/srv/app/src/Handler.php', 7, vars: ['token' => self::SECRETS[4]])]),
            ),
            new ExceptionDataBag(new \LogicException('previa: '.self::SECRETS[0])),
        ]);

        $result = (new SentryEventSanitizer())($event);

        self::assertSame($event, $result);
        $this->assertNoSecretIsTransmitted($event);
        self::assertSame(SentryEventSanitizer::REDACTED, $event->getMessage());
        foreach ($event->getExceptions() as $exception) {
            self::assertSame(SentryEventSanitizer::REDACTED, $exception->getValue());
        }
        // La clase sigue permitiendo diagnosticar sin exponer el texto.
        self::assertSame(
            [\RuntimeException::class, \LogicException::class],
            array_map(static fn (ExceptionDataBag $e): string => $e->getType(), $event->getExceptions()),
        );
    }

    public function testMetadataAndPathSecretsAreDenied(): void
    {
        $event = Event::createEvent();
        $event->setBreadcrumb([
            new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'http', 'Login de '.self::SECRETS[1], [
                'nested' => ['deeper' => ['password' => self::SECRETS[0]]],
            ]),
        ]);
        $event->setExtra(['payload' => ['card' => self::SECRETS[2], 'list' => [self::SECRETS[3]]]]);
        $event->setTags(['customer' => self::SECRETS[1], 'ticket' => self::SECRETS[3]]);
        $event->setContext('checkout', ['session' => ['id' => self::SECRETS[4], 'owner' => self::SECRETS[1]]]);
        $event->setContext('trace', [
            'trace_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'span_id' => '1122334455667788',
            'description' => self::SECRETS[0], 'data' => ['user' => self::SECRETS[1]],
        ]);
        $event->setFingerprint([self::SECRETS[1]]);
        $event->setTransaction('/cuenta/'.self::SECRETS[1]);
        $event->setServerName('host-'.self::SECRETS[3]);
        $event->setLogger('app.'.self::SECRETS[0]);
        $event->setRequest([
            'url' => 'https://www.condorapp.com.co/usuarios/'.self::SECRETS[1].'/token/'.self::SECRETS[4].'/factura/12345?x='.self::SECRETS[0].'#'.self::SECRETS[3],
            'method' => 'POST',
            'query_string' => 'api_key='.self::SECRETS[2],
            'env' => ['REMOTE_ADDR' => '203.0.113.10', 'SECRET' => self::SECRETS[0]],
            'headers' => [
                'X-Api-Key' => [self::SECRETS[2]],
                'X-Csrf-Token' => [self::SECRETS[4]],
                'Authorization' => ['Bearer '.self::SECRETS[4]],
                'X-Request-Id' => ['req-123'],
            ],
            'data' => ['email' => self::SECRETS[1]],
        ]);

        (new SentryEventSanitizer())($event);

        $this->assertNoSecretIsTransmitted($event);
        self::assertSame([], $event->getBreadcrumbs());
        self::assertSame([], $event->getExtra());
        self::assertSame([], $event->getTags());
        self::assertSame([], $event->getFingerprint());
        self::assertNull($event->getTransaction());
        self::assertNull($event->getServerName());
        self::assertNull($event->getLogger());
        self::assertSame(['trace'], array_keys($event->getContexts()));
        self::assertSame(
            ['trace_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'span_id' => '1122334455667788'],
            $event->getContexts()['trace'],
        );
        $request = $event->getRequest();
        self::assertSame(
            'https://www.condorapp.com.co/usuarios/[redacted]/token/[redacted]/factura/[redacted]',
            $request['url'],
        );
        self::assertSame(['method', 'url', 'headers'], array_keys($request));
        self::assertSame(['X-Request-Id' => ['req-123']], $request['headers']);
        // Un esquema o una URL ilegible nunca se reenvían tal cual.
        $event->setRequest(['url' => 'javascript:alert('.self::SECRETS[0].')']);
        (new SentryEventSanitizer())($event);
        self::assertSame(SentryEventSanitizer::REDACTED, $event->getRequest()['url']);
    }

    public function testMinimalSafeTelemetryIsPreserved(): void
    {
        $frame = new Frame('handle', '/srv/app/src/Handler.php', 7, vars: ['token' => self::SECRETS[4]]);
        $event = Event::createEvent();
        $event->setRelease('condor@0.1.194+abcdef0');
        $event->setEnvironment('production');
        $event->setLevel(Severity::error());
        $event->setTimestamp(1_900_000_000.0);
        $event->setContext('trace', ['trace_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'span_id' => '1122334455667788']);
        $event->setRequest(['url' => 'https://www.condorapp.com.co/health', 'method' => 'GET', 'headers' => ['X-Request-Id' => ['req-1']]]);
        $event->setExceptions([new ExceptionDataBag(new \DomainException('texto libre'), new Stacktrace([$frame]))]);

        $result = (new SentryEventSanitizer())($event);

        self::assertSame($event, $result);
        self::assertSame('condor@0.1.194+abcdef0', $event->getRelease());
        self::assertSame('production', $event->getEnvironment());
        self::assertEquals(Severity::error(), $event->getLevel());
        self::assertSame(1_900_000_000.0, $event->getTimestamp());
        self::assertSame(\DomainException::class, $event->getExceptions()[0]->getType());
        $stacktrace = $event->getExceptions()[0]->getStacktrace();
        self::assertNotNull($stacktrace);
        $frames = $stacktrace->getFrames();
        self::assertSame('handle', $frames[0]->getFunctionName());
        self::assertSame(7, $frames[0]->getLine());
        self::assertSame([], $frames[0]->getVars());
        self::assertSame('https://www.condorapp.com.co/health', $event->getRequest()['url']);
        self::assertSame('GET', $event->getRequest()['method']);
        self::assertSame(['trace_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90', 'span_id' => '1122334455667788'], $event->getContexts()['trace']);
        $payload = $this->transmitted($event);
        foreach (['condor@0.1.194+abcdef0', 'production', 'DomainException', 'Handler.php'] as $kept) {
            self::assertStringContainsString($kept, $payload);
        }
        // Lo que no es un evento de error no es telemetría mínima y no se envía.
        self::assertNull((new SentryEventSanitizer())(Event::createTransaction()));
    }
}
