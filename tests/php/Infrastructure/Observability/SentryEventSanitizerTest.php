<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Observability;

use App\Infrastructure\Observability\SentryEventSanitizer;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
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
        self::assertArrayNotHasKey('X-Real-IP', $request['headers']);
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
}
