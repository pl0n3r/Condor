<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use App\Domain\Observability\Entity\ErrorIncident;

final readonly class ErrorIncidentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function json(ErrorIncident $incident, bool $privileged): array
    {
        $payload = [
            'error' => 'internal_error',
            'message' => 'No pudimos completar esta solicitud.',
            'error_id' => $incident->id(),
        ];

        if (!$privileged) {
            return $payload;
        }

        return [
            ...$payload,
            'diagnostic' => $this->diagnostic($incident),
        ];
    }

    public function html(ErrorIncident $incident, bool $privileged): string
    {
        $reference = $this->escape($incident->id());
        $details = '';

        if ($privileged) {
            $diagnostic = $this->diagnostic($incident);
            $rows = [
                'Incidente' => $diagnostic['incident_id'],
                'Request ID' => $diagnostic['request_id'],
                'HTTP' => (string) $diagnostic['status'],
                'Ruta' => $diagnostic['route'],
                'Excepción' => $diagnostic['exception'],
                'Mensaje sanitizado' => $diagnostic['message'],
                'Versión' => $diagnostic['version'],
                'Release SHA' => $diagnostic['release_sha'],
            ];

            $items = '';
            foreach ($rows as $label => $value) {
                $items .= '<div class="diagnostic-row"><dt>'
                    .$this->escape($label)
                    .'</dt><dd><code>'
                    .$this->escape((string) $value)
                    .'</code></dd></div>';
            }

            $details = '<section class="diagnostic-card">'
                .'<p class="owner-note">Visible solo para el propietario de plataforma. '
                .'Los datos mostrados están sanitizados y no incluyen trace, headers, '
                .'cookies, SQL ni secretos.</p>'
                .'<dl>'.$items.'</dl>'
                .'<a class="diagnostic-link" href="/adminpl0n3r/diagnosticos">'
                .'Abrir diagnósticos</a>'
                .'</section>';
        }

        return '<!doctype html><html lang="es-CO"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta name="robots" content="noindex,nofollow">'
            .'<title>Condor App — error interno</title>'
            .'<style>'
            .':root{color-scheme:dark}*{box-sizing:border-box}'
            .'body{margin:0;background:#0b0b0c;color:#f5f5f5;font-family:'
            .'Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}'
            .'main{width:min(920px,calc(100% - 32px));margin:64px auto;padding:32px;'
            .'border:1px solid #29292d;border-radius:18px;background:#121214}'
            .'h1{margin:0 0 12px;font-size:clamp(1.8rem,4vw,3rem)}'
            .'p{color:#b4b4bc;line-height:1.6}.reference{margin-top:24px}'
            .'code{overflow-wrap:anywhere;color:#fff}'
            .'.diagnostic-card{margin-top:28px;padding:20px;border:1px solid #34343a;'
            .'border-radius:14px;background:#0d0d0f}'
            .'.owner-note{margin-top:0}.diagnostic-card dl{display:grid;gap:10px;margin:18px 0}'
            .'.diagnostic-row{display:grid;grid-template-columns:minmax(140px,180px) 1fr;'
            .'gap:16px;padding:10px 0;border-bottom:1px solid #242428}'
            .'.diagnostic-row:last-child{border-bottom:0}.diagnostic-row dt{color:#85858f}'
            .'.diagnostic-row dd{margin:0}.diagnostic-link{display:inline-block;margin-top:8px;'
            .'padding:10px 14px;border:1px solid #424249;border-radius:10px;color:#fff;'
            .'text-decoration:none}'
            .'@media(max-width:640px){main{margin:24px auto;padding:22px}.diagnostic-row{'
            .'grid-template-columns:1fr;gap:4px}}'
            .'</style></head><body><main>'
            .'<p>Condor App · Error interno</p>'
            .'<h1>No pudimos completar esta solicitud.</h1>'
            .'<p>El error quedó registrado de forma segura para diagnóstico.</p>'
            .'<p class="reference">Referencia: <code>'.$reference.'</code></p>'
            .$details
            .'</main></body></html>';
    }

    /**
     * @return array{
     *   incident_id: string,
     *   request_id: string,
     *   status: int,
     *   route: string,
     *   exception: string,
     *   message: string,
     *   version: string,
     *   release_sha: string
     * }
     */
    private function diagnostic(ErrorIncident $incident): array
    {
        return [
            'incident_id' => $incident->id(),
            'request_id' => $incident->requestId(),
            'status' => $incident->status(),
            'route' => $incident->routeName() ?? '(sin ruta)',
            'exception' => $incident->exceptionClass(),
            'message' => $incident->message(),
            'version' => $incident->version(),
            'release_sha' => $incident->releaseSha(),
        ];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
    }
}
