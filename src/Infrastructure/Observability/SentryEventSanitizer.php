<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\Stacktrace;

final class SentryEventSanitizer
{
    /** @var list<string> */
    private const SENSITIVE_HEADERS = [
        'authorization',
        'cookie',
        'set-cookie',
        'referer',
        'x-forwarded-for',
        'x-real-ip',
    ];

    public function __invoke(Event $event, ?EventHint $hint = null): ?Event
    {
        $request = $event->getRequest();

        if ($request !== []) {
            if (isset($request['url']) && is_string($request['url'])) {
                $request['url'] = $this->withoutQuery($request['url']);
            }

            unset(
                $request['query_string'],
                $request['cookies'],
                $request['data'],
            );

            if (isset($request['headers']) && is_array($request['headers'])) {
                foreach (array_keys($request['headers']) as $headerName) {
                    if (
                        is_string($headerName)
                        && in_array(strtolower($headerName), self::SENSITIVE_HEADERS, true)
                    ) {
                        unset($request['headers'][$headerName]);
                    }
                }
            }

            $event->setRequest($request);
        }

        $this->removeFrameVars($event->getStacktrace());

        foreach ($event->getExceptions() as $exception) {
            $this->removeFrameVars($exception->getStacktrace());
        }

        // Incluso si otra integración añadió user context, Condor no lo envía.
        $event->setUser(null);

        return $event;
    }

    private function removeFrameVars(?Stacktrace $stacktrace): void
    {
        if ($stacktrace === null) {
            return;
        }

        foreach ($stacktrace->getFrames() as $frame) {
            $frame->setVars([]);
        }
    }

    private function withoutQuery(string $url): string
    {
        $position = strpos($url, '?');

        return $position === false ? $url : substr($url, 0, $position);
    }
}
