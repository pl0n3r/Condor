<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Domain\Documents\Entity\DocumentRecord;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Infrastructure\Persistence\BranchDocumentRecordRepository;
use DomainException;

/**
 * Construction-only HTTP to SQL bridge. Scope comes exclusively from
 * authenticated BranchApiSupport; never from a request body or session.
 */
final readonly class DocumentDraftPersistence
{
    public function __construct(private BranchDocumentRecordRepository $records)
    {
    }

    /** @return array{reference:string, classification:string, status:string, id:string, version:int} */
    public function create(Branch $branch, User $actor, string $reference, string $classification): array
    {
        self::assertReference($reference);
        if (!in_array($classification, ['PUBLICA', 'INTERNA', 'RESERVADA'], true)) {
            throw new DomainException('Clasificación sintética inválida.');
        }
        [$tenant, $entity, $branchRef, $actorRef] = self::scope($branch, $actor);
        $record = $this->records->createDraft($tenant, $entity, $branchRef,
            self::dossier($reference), 'fixture:version/one', $classification,
            $actorRef, 'editor', true);
        return [
            'reference' => $reference, 'classification' => $classification,
            'status' => $record->state(), 'id' => $record->id(), 'version' => $record->version(),
        ];
    }

    /** @return list<array{reference:string, classification:string, status:string, id:string, version:int}> */
    public function listing(Branch $branch, User $actor): array
    {
        [$tenant, $entity, $branchRef, $actorRef] = self::scope($branch, $actor);
        $rows = $this->records->forBranch($tenant, $entity, $branchRef, $actorRef, 'auditor', true);
        $documents = [];
        foreach ($rows as $row) {
            $reference = 'fixture:doc/'.substr((string) $row['external_ref'], strlen('fixture:dossier/'));
            // forBranch orders versions ascending, exposing only the latest immutable record.
            $documents[$reference] = [
                'reference' => $reference, 'classification' => (string) $row['classification'],
                'status' => (string) $row['state'], 'id' => (string) $row['id'],
                'version' => (int) $row['version'],
            ];
        }
        return array_values($documents);
    }

    /** @return array{reference:string, classification:string, status:string, id:string, version:int} */
    public function detail(Branch $branch, User $actor, string $reference): array
    {
        self::assertReference($reference);
        foreach ($this->listing($branch, $actor) as $document) {
            if ($document['reference'] === $reference) {
                return $document;
            }
        }
        throw new DomainException('Documento sintético no encontrado.');
    }

    private static function dossier(string $reference): string
    {
        return 'fixture:dossier/'.substr($reference, strlen('fixture:doc/'));
    }

    private static function assertReference(string $reference): void
    {
        if (!str_starts_with($reference, 'fixture:doc/')
            || DocumentApiService::referenceFromKey(substr($reference, strlen('fixture:doc/'))) !== $reference) {
            throw new DomainException('Solo se admiten referencias documentales sintéticas.');
        }
    }

    /** @return array{string,string,string,string} */
    private static function scope(Branch $branch, User $actor): array
    {
        $entity = $branch->legalEntity();
        if ($entity === null || $entity->tenant()->id() !== $branch->tenant()->id()) {
            throw new DomainException('Sede sin entidad autorizada.');
        }
        return [
            self::fixture('tenant', $branch->tenant()->id()),
            self::fixture('legal-entity', $entity->id()),
            self::fixture('branch', $branch->id()),
            self::fixture('actor', $actor->id()),
        ];
    }

    /**
     * Injective Crockford ULID transliteration, not a truncation or hash.
     * Separators make seven consecutive digits impossible.
     */
    private static function fixture(string $kind, string $ulid): string
    {
        if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D', $ulid) !== 1) {
            throw new DomainException('Identidad del servidor inválida.');
        }
        $encoded = strtr($ulid, '0123456789ABCDEFGHJKMNPQRSTVWXYZ',
            'abcdefghijklmnopqrstuvwxyz234567');
        return 'fixture:'.$kind.'/'.implode('-', str_split($encoded, 6));
    }
}
