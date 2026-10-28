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
     * @return list<array{reference: string, classification: string, status: string}>
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
     * @return array{reference: string, classification: string, status: string}
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
        return ['state' => $state, 'document' => $doc];
    }

    /**
     * @param array<string, mixed> $state
     * @return array{state: array<string, mixed>, document: array{reference: string, classification: string, status: string}, changed: bool}
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
        return ['state' => $state, 'document' => $doc, 'changed' => true];
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

    /** @phpstan-assert-if-true array{reference: string, classification: string, status: string} $value */
    private function validDocument(mixed $value): bool
    {
        return is_array($value)
            && array_keys($value) === ['reference', 'classification', 'status']
            && is_string($value['reference'])
            && preg_match('~^fixture:doc/[a-z][a-z0-9_-]{0,47}$~D', $value['reference']) === 1
            && in_array($value['classification'], self::CLASSIFICATIONS, true)
            && in_array($value['status'], ['BORRADOR', 'PENDIENTE_REVISION'], true);
    }
}
