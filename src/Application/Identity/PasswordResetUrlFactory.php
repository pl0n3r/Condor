<?php

declare(strict_types=1);

namespace App\Application\Identity;

use DomainException;

final readonly class PasswordResetUrlFactory
{
    private string $canonicalBaseUrl;

    public function __construct(string $canonicalUrl)
    {
        $canonicalUrl = rtrim(trim($canonicalUrl), '/');
        $parts = parse_url($canonicalUrl);

        if (
            $canonicalUrl === ''
            || !is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new DomainException(
                'CONDOR_CANONICAL_URL debe ser una URL HTTPS absoluta y limpia.',
            );
        }

        $this->canonicalBaseUrl = $canonicalUrl;
    }

    public function resetUrl(string $rawToken): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $rawToken) !== 1) {
            throw new DomainException('El token de recuperación no es válido.');
        }

        return $this->canonicalBaseUrl
            .'/admin/restablecer-contrasena#token='
            .$rawToken;
    }
}
