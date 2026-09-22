<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Shared\Id\UlidFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ApiErrorResponseFactory
{
    /**
     * @param array<string, mixed> $details
     * @param array<string, string> $headers
     */
    public function create(
        Request $request,
        string $error,
        string $message,
        int $status,
        array $details = [],
        array $headers = [],
    ): JsonResponse {
        $requestId = $request->attributes->get(RequestIdSubscriber::ATTRIBUTE);
        if (!is_string($requestId) || $requestId === '') {
            $requestId = UlidFactory::new();
            $request->attributes->set(RequestIdSubscriber::ATTRIBUTE, $requestId);
        }

        $payload = [
            'error' => $error,
            'message' => $message,
            'status' => $status,
            'request_id' => $requestId,
        ];

        if ($details !== []) {
            $payload['details'] = $details;
        }

        return new JsonResponse(
            $payload,
            $status,
            array_merge($headers, [
                'Cache-Control' => 'no-store',
                'X-Request-Id' => $requestId,
            ]),
        );
    }
}
