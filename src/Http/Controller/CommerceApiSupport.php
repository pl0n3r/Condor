<?php

declare(strict_types=1);

namespace App\Http\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

trait CommerceApiSupport
{
    /** @param array<string, mixed> $payload */
    private function commercialRequiredString(
        array $payload,
        string $field,
    ): string {
        $value = $payload[$field] ?? null;
        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException(
                sprintf(
                    'El campo %s es obligatorio y debe ser texto.',
                    $field,
                ),
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function commercialNullableString(
        array $payload,
        string $field,
    ): ?string {
        $value = $payload[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException(
                sprintf('El campo %s debe ser texto o null.', $field),
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function commercialRequiredInt(
        array $payload,
        string $field,
    ): int {
        $value = $payload[$field] ?? null;
        if (!is_int($value)) {
            throw new UnprocessableEntityHttpException(
                sprintf(
                    'El campo %s es obligatorio y debe ser entero.',
                    $field,
                ),
            );
        }

        return $value;
    }

    private function commercialFlushUnique(string $message): void
    {
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictHttpException($message, $exception);
        }
    }
}
