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
        self::requireExactFields($payload, [
            'tenant_ref', 'legal_entity_ref', 'site_ref', 'supplier_ref',
            'contract_ref', 'mode', 'metadata', 'reviews',
        ]);
        self::requireFixture($payload['tenant_ref'], 'tenant');
        self::requireFixture($payload['legal_entity_ref'], 'legal-entity');
        self::requireFixture($payload['site_ref'], 'site');
        self::requireFixture($payload['supplier_ref'], 'supplier');
        self::requireFixture($payload['contract_ref'], 'contract');
        if ($payload['mode'] !== 'preview_only') {
            throw new InvalidArgumentException('PREVIEW_ONLY');
        }

        $metadata = $payload['metadata'];
        if (!is_array($metadata) || array_is_list($metadata)) {
            throw new InvalidArgumentException('INVALID_METADATA');
        }
        self::requireExactFields($metadata, ['template_ref', 'kind', 'revision']);
        self::requireFixture($metadata['template_ref'], 'contract-template');
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
            self::requireExactFields($review, ['stage', 'state', 'tenant_ref', 'legal_entity_ref', 'site_ref']);
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

        // Only allowlisted fixture identity survives: no legally binding action.
        $identity = array_intersect_key($payload, array_flip([
            'tenant_ref', 'legal_entity_ref', 'site_ref', 'supplier_ref', 'contract_ref',
        ]));
        $immutableControls = array_fill_keys([
            'can_approve', 'can_sign', 'can_send', 'can_order',
            'juridically_binding', 'persisted', 'notified',
        ], false);

        return $identity + [
            'metadata' => $metadata,
            'reviews' => $reviews,
            'status' => 'REQUIRES_HUMAN_REVIEW',
            'mode' => 'preview_only',
        ] + $immutableControls;
    }

    /** @param array<string,mixed> $source
     *  @param list<string> $fields
     */
    private static function requireExactFields(array $source, array $fields): void
    {
        if (count($source) !== count($fields)) {
            throw new InvalidArgumentException('UNEXPECTED_FIELDS');
        }
        foreach ($fields as $field) {
            if (!array_key_exists($field, $source)) {
                throw new InvalidArgumentException('UNEXPECTED_FIELDS');
            }
        }
    }

    private static function requireFixture(mixed $reference, string $type): void
    {
        if (!is_string($reference)) {
            throw new InvalidArgumentException('INVALID_FIXTURE');
        }
        $prefix = 'fixture:'.$type.'/';
        if (!str_starts_with($reference, $prefix)) {
            throw new InvalidArgumentException('INVALID_FIXTURE');
        }
        $identifier = substr($reference, strlen($prefix));
        if ($identifier === '' || strlen($identifier) > 64
            || preg_match('/\A[a-z0-9][a-z0-9-]*\z/D', $identifier) !== 1) {
            throw new InvalidArgumentException('INVALID_FIXTURE');
        }
    }
}
