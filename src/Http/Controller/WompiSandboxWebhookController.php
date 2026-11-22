<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Infrastructure\Payments\Wompi\WompiSandboxHttpTransport;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public callback for sandbox events only. HMAC validation replaces user
 * authentication; never hydrates tenants, creates payments or touches ledger.
 */
final readonly class WompiSandboxWebhookController
{
    public function __construct(private WompiSandboxHttpTransport $transport)
    {
    }

    #[Route('/webhooks/wompi/sandbox', name: 'wompi_sandbox_webhook', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!WompiSandboxHttpTransport::enabled()) {
            return new JsonResponse(['error' => 'Sandbox deshabilitado.'], Response::HTTP_LOCKED);
        }
        $payload = $request->getContent();
        $checksum = $request->headers->get('X-Event-Checksum', '');
        if (strlen($payload) > 16384
            || !$this->transport->verifyEvent($payload, $checksum)) {
            // No raw payload, metadata, key or untrusted event in response/logs.
            return new JsonResponse(['error' => 'Firma inválida.'], Response::HTTP_FORBIDDEN);
        }

        // Wompi explicitly requires status 200 (not 204) to stop retries.
        return new JsonResponse(['accepted' => true], Response::HTTP_OK);
    }
}
