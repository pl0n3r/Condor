<?php

declare(strict_types=1);

namespace App\Application\Compliance;

/**
 * F3d: static SG-SST attention preview for synthetic fixtures only.
 *
 * No HTTP, personal data, legal decisions, persistence or external effects.
 * Never expose opaque references, source labels or submitted HTML.
 */
final class OccupationalSafetyAttentionPreview
{
    /**
     * @param array<array-key, mixed> $checklist Fictional F3b input
     * @param array<array-key, mixed> $followUp Fictional F3c input
     * @param array<array-key, mixed> $precheck Fictional F3a projection
     */
    public static function render(
        array $checklist,
        array $followUp,
        array $precheck,
        string $tenantRef,
        string $legalEntityRef
    ): string {
        // Validate the context *before* constructing any visible card.
        if (!self::fixture($tenantRef, 'tenant')
            || !self::fixture($legalEntityRef, 'legal-entity')
        ) {
            return self::html(['REQUIERE_REVISION']);
        }

        $evidence = OccupationalSafetyEvidenceChecklistProjection::project(
            $checklist, $precheck, $tenantRef, $legalEntityRef
        );
        $actions = OccupationalSafetyFollowUpProjection::project(
            $followUp, $evidence, $tenantRef, $legalEntityRef
        );

        // Empty, inconsistent, cross-tenant and duplicate payloads are never
        // rendered as an accepted or completed attention item.
        if ($evidence['structurally_reviewable'] !== true
            || $actions['structurally_reviewable'] !== true
            // F3b/F3c validate their own payloads, not their cross-object link.
            || !isset($checklist['fixture_ref'], $followUp['checklist_ref'])
            || $checklist['fixture_ref'] !== $followUp['checklist_ref']
            || $evidence['evidence_count'] < 1
            || $actions['action_count'] < 1
            || !in_array($evidence['state'], ['PENDIENTE', 'REQUIERE_REVISION'], true)
            || !in_array($actions['state'], ['PENDIENTE', 'REQUIERE_REVISION'], true)
        ) {
            return self::html(['REQUIERE_REVISION']);
        }

        // The projections have already checked all fixture references, keys,
        // tenancy, entity and state vocabulary. None are included in HTML.
        $states = [$evidence['state'], $actions['state']];
        foreach ($actions['actions'] as $action) {
            $states[] = $action['state'] === 'PENDIENTE'
                ? 'PENDIENTE' : 'REQUIERE_REVISION';
        }

        return self::html($states);
    }

    private static function fixture(mixed $value, string $type): bool
    {
        return is_string($value)
            && preg_match('~^fixture:' . $type . '/[a-z0-9][a-z0-9_-]{0,47}$~D', $value) === 1;
    }

    /** @param list<string> $states */
    private static function html(array $states): string
    {
        $cards = '';
        foreach ($states as $index => $state) {
            $safeState = $state === 'PENDIENTE' ? 'PENDIENTE' : 'REQUIERE_REVISION';
            $label = $index === 0 ? 'Evidencias ficticias'
                : ($index === 1 ? 'Seguimiento ficticio' : 'Acción ficticia');
            $cards .= '<li style="border:1px solid currentColor;padding:1rem;border-radius:.5rem">'
                . '<h3>' . $label . '</h3>'
                . '<p><strong>' . $safeState . '</strong></p></li>';
        }

        return '<section lang="es-CO" aria-label="Vista ficticia de pendientes SG-SST">'
            . '<h2>Pendientes SG-SST (ejemplo)</h2>'
            . '<p>Vista ficticia de solo lectura. Requiere revisión profesional; '
            . 'no certifica cumplimiento ni aplicabilidad legal.</p>'
            . '<ul style="display:grid;grid-template-columns:repeat(auto-fit,'
            . 'minmax(min(100%,16rem),1fr));gap:1rem;list-style:none;padding:0">'
            . $cards . '</ul></section>';
    }
}
