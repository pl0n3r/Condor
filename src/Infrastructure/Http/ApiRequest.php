<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;

final class ApiRequest
{
    public static function matches(Request $request): bool
    {
        $path = $request->getPathInfo();

        return str_starts_with($path, '/api/')
            || str_starts_with($path, '/adminpl0n3r/api/');
    }
}
