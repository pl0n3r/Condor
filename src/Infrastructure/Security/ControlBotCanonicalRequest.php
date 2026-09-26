<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ControlBotCanonicalRequest
{
    public static function canonical(
        Request $request,
        string $keyId,
        string $timestamp,
        string $nonce,
    ): string {
        return implode("\n", [
            $keyId,
            strtoupper($request->getMethod()),
            self::pathWithSortedQuery($request),
            $timestamp,
            $nonce,
            hash('sha256', (string) $request->getContent()),
        ]);
    }

    public static function pathWithSortedQuery(Request $request): string
    {
        $path = $request->getPathInfo();
        $rawQuery = (string) $request->server->get('QUERY_STRING', '');
        if ($rawQuery === '') {
            return $path;
        }

        $pairs = [];
        foreach (explode('&', $rawQuery) as $fragment) {
            if (substr_count($fragment, '=') !== 1) {
                throw new UnprocessableEntityHttpException(
                    'Query no canónica: cada par requiere exactamente un =.',
                );
            }
            [$rawName, $rawValue] = explode('=', $fragment, 2);
            if ($rawName === '') {
                throw new UnprocessableEntityHttpException(
                    'Query no canónica: nombre vacío.',
                );
            }
            $pairs[] = [
                self::canonicalComponent($rawName),
                self::canonicalComponent($rawValue),
            ];
        }

        usort(
            $pairs,
            static fn (array $left, array $right): int =>
                strcmp($left[0], $right[0]) ?: strcmp($left[1], $right[1]),
        );

        return $path.'?'.implode('&', array_map(
            static fn (array $pair): string => $pair[0].'='.$pair[1],
            $pairs,
        ));
    }

    private static function canonicalComponent(string $raw): string
    {
        if (str_contains($raw, '+')) {
            throw new UnprocessableEntityHttpException(
                'Query no canónica: + crudo no permitido.',
            );
        }
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $raw) === 1) {
            throw new UnprocessableEntityHttpException(
                'Query no canónica: escape porcentual inválido.',
            );
        }

        $decoded = rawurldecode($raw);
        if (!mb_check_encoding($decoded, 'UTF-8')) {
            throw new UnprocessableEntityHttpException(
                'Query no canónica: UTF-8 inválido.',
            );
        }

        return rawurlencode($decoded);
    }
}
