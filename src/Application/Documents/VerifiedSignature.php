<?php

declare(strict_types=1);

namespace App\Application\Documents;

use DomainException;

/**
 * Legal gate M08: no identity, consent, signature vendor, evidentiary retention
 * or independent legal review is authorised in the construction environment.
 * A fixture receipt is NEVER a verified electronic signature.
 */
final class VerifiedSignature
{
    public const STATUS = 'NO_VERIFICABLE';

    /** @return array{status:string,legal_signature:bool,can_close:bool,requires_legal_decision:bool} */
    public static function gate(): array
    {
        return [
            'status' => self::STATUS,
            'legal_signature' => false,
            'can_close' => false,
            'requires_legal_decision' => true,
        ];
    }

    public static function assertAuthorized(): never
    {
        throw new DomainException(
            'Firma jurídica bloqueada: faltan decisión legal, identidad, proveedor y auditoría persistente.',
        );
    }
}
