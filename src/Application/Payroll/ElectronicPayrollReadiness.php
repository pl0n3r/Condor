<?php

declare(strict_types=1);

namespace App\Application\Payroll;

/**
 * M04/S09: deterministic, synthetic-only readiness contract.
 * Deliberately no DIAN client, persistence, provider calls or legal effects.
 */
final class ElectronicPayrollReadiness
{
    public const CONTRACT_VERSION = 'electronic-payroll-readiness-v1';

    private const REQUIRED_FIELDS = [
        'tenant_ref', 'company_ref', 'synthetic_document_ref', 'synthetic', 'mode',
    ];

    private const ALLOWED_FIELDS = [
        ...self::REQUIRED_FIELDS, 'request_send',
    ];

    /**
     * All actor scope and permission arguments MUST come from trusted server
     * authentication/authorization, never from the submitted fixture. This pure
     * offline method has no HTTP adapter and can never enable transmission.
     *
     * @param array<string, mixed> $input Fictitious references only, no records or PII.
     * @return array{
     *   contract:string,state:string,review_state:string,production_enabled:bool,
     *   transmitted:bool,provider_calls:bool,acknowledgement:null,legal_effect:bool,
     *   errors:list<string>
     * }
     */
    public static function plan(
        array $input,
        string $actorTenantRef,
        string $actorCompanyRef,
        bool $mayPrepare = false,
    ): array
    {
        $errors = [];
        if (!$mayPrepare) {
            $errors[] = 'PERMISSION_DENIED';
        }

        foreach (array_keys($input) as $field) {
            if (!is_string($field) || !in_array($field, self::ALLOWED_FIELDS, true)) {
                $errors[] = 'UNRECOGNIZED_FIELD';
                break;
            }
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $input)) {
                $errors[] = 'REQUIRED_FIELD_MISSING';
                break;
            }
        }

        if (!self::syntheticRef($input['tenant_ref'] ?? null, 'tenant')
            || !self::syntheticRef($input['company_ref'] ?? null, 'company')
            || !self::syntheticRef($input['synthetic_document_ref'] ?? null, 'document')) {
            $errors[] = 'INVALID_SYNTHETIC_REFERENCE';
        }

        if (!self::syntheticRef($actorTenantRef, 'tenant')
            || $actorTenantRef !== ($input['tenant_ref'] ?? null)) {
            $errors[] = 'TENANT_MISMATCH';
        }

        if (!self::syntheticRef($actorCompanyRef, 'company')
            || $actorCompanyRef !== ($input['company_ref'] ?? null)) {
            $errors[] = 'COMPANY_MISMATCH';
        }

        if (($input['synthetic'] ?? null) !== true) {
            $errors[] = 'SYNTHETIC_ONLY';
        }
        if (($input['mode'] ?? null) !== 'offline') {
            $errors[] = 'OFFLINE_ONLY';
        }
        if (array_key_exists('request_send', $input) && $input['request_send'] !== false) {
            $errors[] = 'TRANSMISSION_FORBIDDEN';
        }

        return [
            'contract' => self::CONTRACT_VERSION,
            'state' => 'NO_HABILITADO',
            'review_state' => 'PENDIENTE_REVISION',
            'production_enabled' => false,
            'transmitted' => false,
            'provider_calls' => false,
            'acknowledgement' => null,
            'legal_effect' => false,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    private static function syntheticRef(mixed $value, string $kind): bool
    {
        return is_string($value)
            && preg_match('~^fixture:'.preg_quote($kind, '~').'/[A-Za-z0-9_-]{1,60}$~D', $value) === 1;
    }
}
