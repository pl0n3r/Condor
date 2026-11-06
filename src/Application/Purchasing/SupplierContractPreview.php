<?php

declare(strict_types=1);

namespace App\Application\Purchasing;

use InvalidArgumentException;

/** M09/S07: pure synthetic contract review. No legal document, signature or persistence. */
final class SupplierContractPreview
{
    private const STAGES = ['purchasing', 'legal', 'compliance'];
    private const STATES = ['pending', 'changes_requested', 'reviewed'];

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    public static function preview(array $payload): array
    {
        self::exactKeys($payload, [
            'tenant_ref', 'legal_entity_ref', 'site_ref', 'supplier_ref',
            'contract_ref', 'mode', 'metadata', 'reviews',
        ]);
        self::fixture($payload['tenant_ref'], 'tenant');
        self::fixture($payload['legal_entity_ref'], 'legal-entity');
        self::fixture($payload['site_ref'], 'site');
        self::fixture($payload['supplier_ref'], 'supplier');
        self::fixture($payload['contract_ref'], 'contract');
        if ($payload['mode'] !== 'preview_only') {
            throw new InvalidArgumentException('PREVIEW_ONLY');
        }

        $metadata = $payload['metadata'];
        if (!is_array($metadata) || array_is_list($metadata)) {
            throw new InvalidArgumentException('INVALID_METADATA');
        }
        self::exactKeys($metadata, ['template_ref', 'kind', 'revision']);
        self::fixture($metadata['template_ref'], 'contract-template');
        if (!is_string($metadata['kind']) || !in_array($metadata['kind'], ['supplies', 'services'], true)) {
            throw new InvalidArgumentException('INVALID_KIND');
        }
        if (!is_int($metadata['revision']) || $metadata['revision'] < 1 || $metadata['revision'] > 99) {
            throw new InvalidArgumentException('INVALID_REVISION');
        }

        $submitted = $payload['reviews'];
        if (!is_array($submitted) || !array_is_list($submitted) || count($submitted) !== 3) {
            throw new InvalidArgumentException('INVALID_REVIEWS');
        }
        $reviews = [];
        foreach ($submitted as $review) {
            if (!is_array($review) || array_is_list($review)) {
                throw new InvalidArgumentException('INVALID_REVIEW');
            }
            self::exactKeys($review, ['stage', 'state', 'tenant_ref', 'legal_entity_ref', 'site_ref']);
            $stage = $review['stage'];
            $state = $review['state'];
            if (!is_string($stage) || !in_array($stage, self::STAGES, true) || isset($reviews[$stage])) {
                throw new InvalidArgumentException('INVALID_STAGE');
            }
            if (!is_string($state) || !in_array($state, self::STATES, true)) {
                throw new InvalidArgumentException('INVALID_STATE');
            }
            foreach (['tenant_ref', 'legal_entity_ref', 'site_ref'] as $scope) {
                if ($review[$scope] !== $payload[$scope]) {
                    throw new InvalidArgumentException('SCOPE_MISMATCH');
                }
            }
            $reviews[$stage] = $state;
        }
        ksort($reviews);

        // Even three reviewed steps can never approve, sign or legalize a contract.
        return [
            'tenant_ref' => $payload['tenant_ref'],
            'legal_entity_ref' => $payload['legal_entity_ref'],
            'site_ref' => $payload['site_ref'],
            'supplier_ref' => $payload['supplier_ref'],
            'contract_ref' => $payload['contract_ref'],
            'metadata' => $metadata,
            'reviews' => $reviews,
            'status' => 'REQUIRES_HUMAN_REVIEW',
            'mode' => 'preview_only',
            'can_approve' => false,
            'can_sign' => false,
            'can_send' => false,
            'can_order' => false,
            'juridically_binding' => false,
            'persisted' => false,
            'notified' => false,
        ];
    }

    /** @param array<string,mixed> $data
     *  @param list<string> $keys
     */
    private static function exactKeys(array $data, array $keys): void
    {
        $actual = array_keys($data);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new InvalidArgumentException('UNEXPECTED_FIELDS');
        }
    }

    private static function fixture(mixed $value, string $kind): void
    {
        if (!is_string($value)
            || preg_match('~^fixture:'.preg_quote($kind, '~').'/[a-z0-9][a-z0-9-]{0,63}$~D', $value) !== 1) {
            throw new InvalidArgumentException('INVALID_FIXTURE');
        }
    }
}
