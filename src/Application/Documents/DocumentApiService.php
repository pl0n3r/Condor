<?php

declare(strict_types=1);

namespace App\Application\Documents;

use DomainException;

/**
 * Synthetic M08 HTTP projection. Only fixture references and enumerated metadata
 * live in a short-lived server session; no uploads, personal data, persistence,
 * legal approval, irreversible transition or external I/O are implemented.
 */
final class DocumentApiService
{
    private const MAX_DOCUMENTS = 100;
    private const CLASSIFICATIONS = ['PUBLICA', 'INTERNA', 'RESERVADA'];

    /**
     * @param array<string, mixed> $state
     * @return list<array{reference: string, classification: string, status: string, evidence_ref?: string}>
     */
    public function listing(array $state, string $scope): array
    {
        $docs = $state[$scope] ?? [];
        if (!is_array($docs)) {
            return [];
        }
        $result = [];
        foreach ($docs as $doc) {
            if ($this->validDocument($doc)) {
                $result[] = $doc;
            }
        }
        usort($result, static fn (array $a, array $b): int => strcmp($a['reference'], $b['reference']));
        return $result;
    }

    /**
     * @param array<string, mixed> $state
     * @return array{reference: string, classification: string, status: string, evidence_ref?: string}
     */
    public function detail(array $state, string $scope, string $reference): array
    {
        self::requireReference($reference);
        $docs = $state[$scope] ?? [];
        $doc = is_array($docs) ? ($docs[$reference] ?? null) : null;
        if (!$this->validDocument($doc) || $doc['reference'] !== $reference) {
            throw new DomainException('Documento sintético no encontrado.');
        }
        return $doc;
    }

    /**
     * @param array<string, mixed> $state
     * @return array{state: array<string, mixed>, document: array{reference: string, classification: string, status: string}}
     */
    public function create(array $state, string $scope, string $reference, string $classification): array
    {
        self::requireReference($reference);
        if (!in_array($classification, self::CLASSIFICATIONS, true)) {
            throw new DomainException('Clasificación sintética inválida.');
        }
        $docs = $state[$scope] ?? [];
        if (!is_array($docs)) {
            throw new DomainException('Estado documental inválido.');
        }
        if (array_key_exists($reference, $docs)) {
            throw new DomainException('Referencia sintética duplicada.');
        }
        if (count($docs) >= self::MAX_DOCUMENTS) {
            throw new DomainException('Se alcanzó el límite de documentos sintéticos.');
        }
        $doc = ['reference' => $reference, 'classification' => $classification, 'status' => 'BORRADOR'];
        $docs[$reference] = $doc;
        $state[$scope] = $docs;
        $state = $this->appendEvent($state, $scope, $reference, 'CREADO');
        return ['state' => $state, 'document' => $doc];
    }

    /**
     * @param array<string, mixed> $state
     * @return array{state: array<string, mixed>, document: array{reference: string, classification: string, status: string, evidence_ref?: string}, changed: bool}
     */
    public function requestReview(array $state, string $scope, string $reference): array
    {
        $doc = $this->detail($state, $scope, $reference);
        if ($doc['status'] === 'PENDIENTE_REVISION') {
            return ['state' => $state, 'document' => $doc, 'changed' => false];
        }
        if ($doc['status'] !== 'BORRADOR') {
            throw new DomainException('La transición documental no está autorizada.');
        }
        $doc['status'] = 'PENDIENTE_REVISION';
        $docs = $state[$scope] ?? [];
        if (!is_array($docs)) {
            throw new DomainException('Estado documental inválido.');
        }
        $docs[$reference] = $doc;
        $state[$scope] = $docs;
        $state = $this->appendEvent($state, $scope, $reference, 'REVISION_SOLICITADA');
        return ['state' => $state, 'document' => $doc, 'changed' => true];
    }

    /**
     * Associate a fixture-only evidence reference. It is NOT a signature, legal
     * attestation, proof of identity or an approval.
     * @param array<string, mixed> $state
     * @return array{state: array<string, mixed>, document: array<string, mixed>, changed: bool}
     */
    public function attachEvidence(array $state, string $scope, string $reference, string $evidenceRef): array
    {
        $doc = $this->detail($state, $scope, $reference);
        if (preg_match('~^fixture:evidence/[a-z][a-z0-9_-]{0,47}$~D', $evidenceRef) !== 1) {
            throw new DomainException('Solo se admite evidencia de prueba.');
        }
        if (isset($doc['evidence_ref'])) {
            if ($doc['evidence_ref'] !== $evidenceRef) {
                throw new DomainException('La evidencia no se puede sustituir.');
            }
            return ['state' => $state, 'document' => $doc, 'changed' => false];
        }
        if ($doc['status'] !== 'BORRADOR') {
            throw new DomainException('No se permite añadir evidencia tras la revisión.');
        }
        $doc['evidence_ref'] = $evidenceRef;
        $docs = $state[$scope] ?? [];
        if (!is_array($docs)) {
            throw new DomainException('Estado documental inválido.');
        }
        $docs[$reference] = $doc;
        $state[$scope] = $docs;
        $state = $this->appendEvent($state, $scope, $reference, 'EVIDENCIA_ADJUNTADA');
        return ['state' => $state, 'document' => $doc, 'changed' => true];
    }

    /** @param array<string, mixed> $state
     * @return array{documents: list<array<string, mixed>>, synthetic: bool, legal_signature: bool, can_close: bool}
     */
    public function export(array $state, string $scope): array
    {
        return [
            'documents' => $this->listing($state, $scope),
            'synthetic' => true, 'legal_signature' => false, 'can_close' => false,
        ];
    }

    /** @param array<string, mixed> $state
     * @return array{reference: string, events: array<mixed>, synthetic: bool, can_close: bool}
     */
    public function trace(array $state, string $scope, string $reference): array
    {
        $this->detail($state, $scope, $reference);
        $history = $state['trace:'.$scope] ?? [];
        $events = is_array($history) ? ($history[$reference] ?? []) : [];
        return [
            'reference' => $reference, 'events' => is_array($events) ? $events : [],
            'synthetic' => true, 'can_close' => false,
        ];
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function appendEvent(array $state, string $scope, string $reference, string $action): array
    {
        $key = 'trace:'.$scope;
        $history = $state[$key] ?? [];
        if (!is_array($history)) {
            throw new DomainException('Historial sintético inválido.');
        }
        $events = $history[$reference] ?? [];
        if (!is_array($events) || count($events) >= 100) {
            throw new DomainException('Límite de eventos sintéticos alcanzado.');
        }
        $events[] = ['sequence' => count($events) + 1, 'action' => $action];
        $history[$reference] = $events;
        $state[$key] = $history;
        return $state;
    }

    public static function referenceFromKey(string $key): string
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,47}$/D', $key) !== 1) {
            throw new DomainException('Referencia sintética inválida.');
        }
        return 'fixture:doc/'.$key;
    }

    private static function requireReference(string $reference): void
    {
        if (preg_match('~^fixture:doc/[a-z][a-z0-9_-]{0,47}$~D', $reference) !== 1) {
            throw new DomainException('Solo se admiten referencias documentales sintéticas.');
        }
    }

    /** @phpstan-assert-if-true array{reference: string, classification: string, status: string, evidence_ref?: string} $value */
    private function validDocument(mixed $value): bool
    {
        return is_array($value)
            && in_array(array_keys($value), [
                ['reference', 'classification', 'status'],
                ['reference', 'classification', 'status', 'evidence_ref'],
            ], true)
            && is_string($value['reference'])
            && preg_match('~^fixture:doc/[a-z][a-z0-9_-]{0,47}$~D', $value['reference']) === 1
            && in_array($value['classification'], self::CLASSIFICATIONS, true)
            && in_array($value['status'], ['BORRADOR', 'PENDIENTE_REVISION'], true)
            && (!array_key_exists('evidence_ref', $value)
                || (is_string($value['evidence_ref'])
                    && preg_match('~^fixture:evidence/[a-z][a-z0-9_-]{0,47}$~D', $value['evidence_ref']) === 1));
    }
}
