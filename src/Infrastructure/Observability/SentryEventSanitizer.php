<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\Stacktrace;

/**
 * before_send de Sentry bajo política fail-closed: solo sale telemetría mínima
 * y segura (clase de la excepción, trazas sin variables, release, entorno,
 * nivel y marca de tiempo). Todo texto libre o metadato que una integración
 * pueda haber rellenado con datos personales se descarta; si no se puede
 * demostrar que el evento quedó saneado, el evento no se envía (null).
 */
final class SentryEventSanitizer
{
    public const REDACTED = '[redacted]';

    /** Única cabecera de petición que se conserva: identificador de correlación sin datos. */
    private const ALLOWED_HEADERS = ['x-request-id', 'content-type', 'accept'];

    /** Del contexto `trace` solo se envían identificadores hexadecimales. */
    private const TRACE_KEYS = ['trace_id', 'span_id', 'parent_span_id'];

    public function __invoke(Event $event, ?EventHint $hint = null): ?Event
    {
        // Solo se envían errores; transacciones, check-ins u otros tipos no son telemetría mínima.
        if ((string) $event->getType() !== 'event') {
            return null;
        }

        try {
            $this->sanitizeRequest($event);

            // Mensajes de excepción y del evento: pueden incrustar credenciales, correos o ids.
            if ($event->getMessage() !== null) {
                $event->setMessage(self::REDACTED, [], self::REDACTED);
            }
            foreach ($event->getExceptions() as $exception) {
                $exception->setValue(self::REDACTED);
                $this->removeFrameVars($exception->getStacktrace());
            }
            $this->removeFrameVars($event->getStacktrace());

            // Metadatos libres: nunca se envían, tampoco sus subestructuras.
            $event->setBreadcrumb([]);
            $event->setExtra([]);
            $event->setTags([]);
            $event->setFingerprint([]);
            $event->setTransaction(null);
            $event->setServerName(null);
            $event->setLogger(null);
            $event->setLogs([]);
            $event->setMetrics([]);
            $event->setMetricsSummary([]);
            // Incluso si otra integración añadió user context, Condor no lo envía.
            $event->setUser(null);

            if (!$this->restrictContexts($event)) {
                return null;
            }
        } catch (\Throwable) {
            // Sin sanitización demostrable no se transmite nada.
            return null;
        }

        return $event;
    }

    private function sanitizeRequest(Event $event): void
    {
        $request = $event->getRequest();
        if ($request === []) {
            return;
        }

        $clean = [];
        if (isset($request['method']) && is_string($request['method'])) {
            $clean['method'] = $request['method'];
        }
        if (isset($request['url']) && is_string($request['url'])) {
            $clean['url'] = $this->safeUrl($request['url']);
        }
        if (isset($request['headers']) && is_array($request['headers'])) {
            foreach ($request['headers'] as $name => $value) {
                if (is_string($name) && in_array(strtolower($name), self::ALLOWED_HEADERS, true)) {
                    $clean['headers'][$name] = $value;
                }
            }
        }

        // query_string, cookies, data, env y cualquier otra clave se descartan.
        $event->setRequest($clean);
    }

    /** Sin query ni fragmento y con el path reducido a segmentos que son palabras en minúscula. */
    private function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return self::REDACTED;
        }

        $segments = [];
        foreach (explode('/', (string) ($parts['path'] ?? '')) as $segment) {
            if ($segment === '') {
                continue;
            }
            // Ids, tokens, correos o números salen reemplazados; las rutas del propio producto no.
            $segments[] = preg_match('/^[a-z]+(?:-[a-z]+)*$/D', $segment) === 1 && strlen($segment) <= 32
                ? $segment
                : self::REDACTED;
        }
        $path = $segments === [] ? '' : '/'.implode('/', $segments);

        return strtolower($parts['scheme']).'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$path;
    }

    /**
     * La API pública solo permite añadir contextos, no quitarlos: se reconstruye
     * la propiedad con una lista cerrada. Falla cerrado si la estructura cambia.
     */
    private function restrictContexts(Event $event): bool
    {
        $contexts = $event->getContexts();
        $kept = [];
        if (isset($contexts['trace'])) {
            foreach (self::TRACE_KEYS as $key) {
                $value = $contexts['trace'][$key] ?? null;
                if (is_string($value) && preg_match('/^[0-9a-f]{16,32}$/D', $value) === 1) {
                    $kept['trace'][$key] = $value;
                }
            }
        }

        try {
            $property = new \ReflectionProperty(Event::class, 'contexts');
            $property->setValue($event, $kept);
        } catch (\ReflectionException) {
            return false;
        }

        return $event->getContexts() === $kept;
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
}
