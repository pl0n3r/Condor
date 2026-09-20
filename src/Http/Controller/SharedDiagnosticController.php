<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Observability\DiagnosticShareService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class SharedDiagnosticController
{
    #[Route(
        '/support/diagnostics/{token}',
        name: 'app_shared_diagnostic',
        requirements: ['token' => '[a-f0-9]{64}'],
        methods: ['GET'],
    )]
    public function __invoke(
        string $token,
        DiagnosticShareService $shares,
    ): JsonResponse {
        $share = $shares->resolve($token);
        if ($share === null) {
            return new JsonResponse(
                ['error' => 'Diagnóstico no disponible.'],
                404,
                [
                    'Cache-Control' => 'no-store',
                    'X-Robots-Tag' => 'noindex, nofollow',
                    'Referrer-Policy' => 'no-referrer',
                ],
            );
        }

        $incident = $share->incident();

        return new JsonResponse([
            'diagnostic' => [
                'error_id' => $incident->id(),
                'request_id' => $incident->requestId(),
                'occurred_at' => $incident->occurredAt()->format(DATE_ATOM),
                'http_status' => $incident->status(),
                'method' => $incident->method(),
                'route' => $incident->routeName(),
                'exception' => $incident->exceptionClass(),
                'message' => $incident->message(),
                'fingerprint' => $incident->fingerprint(),
                'release' => [
                    'version' => $incident->version(),
                    'sha' => $incident->releaseSha(),
                ],
                'trace' => $incident->trace(),
            ],
            'share' => [
                'expires_at' => $share->expiresAt()->format(DATE_ATOM),
                'read_only' => true,
                'sanitized' => true,
            ],
        ], 200, [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
