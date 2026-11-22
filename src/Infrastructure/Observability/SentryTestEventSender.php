<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use App\Shared\Version\AppVersion;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\Transport\ResultStatus;
use Throwable;

/**
 * A single synthetic, opt-in error event, using the configured production Sentry
 * client and its before_send sanitizer. Never initializes a second client/DSN.
 */
class SentryTestEventSender
{
    public function __construct(
        private readonly SentryEventSanitizer $sanitizer,
        private readonly AppVersion $version,
    ) {
    }

    public function send(): string
    {
        $hub = SentrySdk::getCurrentHub();
        $client = $hub->getClient();
        if ($client === null || $client->getOptions()->getDsn() === null) {
            throw new \DomainException('dsn_invalid');
        }

        $event = Event::createEvent();
        $event->setMessage('Prueba de Sentry · Condor', [], 'Prueba de Sentry · Condor');
        $event->setLevel(Severity::error());
        $event->setRelease(sprintf('condor@%s+%s', $this->version->human(), $this->version->releaseSha()));
        $event->setEnvironment('production');

        // Enforce the existing strict sanitizer even if SDK configuration drifts.
        $safeEvent = ($this->sanitizer)($event);
        if ($safeEvent === null) {
            throw new \DomainException('rejected');
        }

        try {
            $eventId = $hub->captureEvent($safeEvent);
            if ($eventId === null) {
                throw new \DomainException('rejected');
            }
            $result = $client->flush(3);
        } catch (\DomainException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new \DomainException('network');
        }

        if (in_array($result->getStatus(), [
            ResultStatus::invalid(), ResultStatus::rateLimit(), ResultStatus::skipped(),
            ResultStatus::contentTooLarge(),
        ], true)) {
            throw new \DomainException('rejected');
        }
        if ($result->getStatus() !== ResultStatus::success()) {
            throw new \DomainException('network');
        }

        return (string) $eventId;
    }
}
