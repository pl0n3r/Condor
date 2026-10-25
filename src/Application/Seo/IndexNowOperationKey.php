<?php

declare(strict_types=1);

namespace App\Application\Seo;

use InvalidArgumentException;

/** A revision-specific, tenant-scoped idempotency key (no PII or URLs in logs). */
final class IndexNowOperationKey
{
    public static function fromPublication(string $tenantId, string $canonicalUrl, string $revision): string
    {
        if ($tenantId === '' || $canonicalUrl === '' || $revision === '') {
            throw new InvalidArgumentException('La identidad de publicación es obligatoria.');
        }

        return hash('sha256', implode("\0", ['indexnow-v1', $tenantId, $canonicalUrl, $revision]));
    }
}
