<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Documents\Entity\DocumentRecord;
use App\Infrastructure\Persistence\BranchDocumentRecordRepository;
use App\Infrastructure\Persistence\DocumentRecordRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** #2019: disposable real MariaDB, never HTTP, SQLite or production. */
final class GestDocumentBranchScopeSqlTest extends KernelTestCase
{
    public function testSiblingBranchesAndForeignScopesStayDisjointFromLegacy(): void
    {
        $db = $this->database();
        $repo = new BranchDocumentRecordRepository($db);
        $db->beginTransaction();
        try {
            $a = self::draft($repo, 'a', 'main', 'north');
            $b = self::draft($repo, 'a', 'main', 'south');
            $otherEntity = self::draft($repo, 'a', 'other', 'north');
            $otherTenant = self::draft($repo, 'b', 'main', 'north');
            $legacy = DocumentRecord::open(self::tenant('a'), self::entity('main'),
                'fixture:dossier/legacy', 'fixture:version/one', 'INTERNA', self::actor());
            (new DocumentRecordRepository($db))->append($legacy, self::tenant('a'),
                self::entity('main'), self::actor(), 'editor', true);

            self::assertNotSame($a->recordRef(), $b->recordRef());
            self::assertNotSame($a->recordRef(), $otherTenant->recordRef());
            foreach ([
                ['a', 'main', 'north', $a], ['a', 'main', 'south', $b],
                ['a', 'other', 'north', $otherEntity], ['b', 'main', 'north', $otherTenant],
            ] as [$tenant, $entity, $branch, $document]) {
                $rows = $repo->forBranch(self::tenant($tenant), self::entity($entity),
                    self::branch($branch), 'fixture:actor/auditor', 'auditor', true);
                self::assertCount(1, $rows);
                self::assertSame($document->id(), $rows[0]['id']);
                self::assertSame('fixture:dossier/shared', $rows[0]['external_ref']);
                self::assertSame($document->snapshot()['fingerprint'], $rows[0]['fingerprint']);
            }
            self::assertSame(2, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM condor_document_branch_link WHERE tenant_ref = ? AND legal_entity_ref = ?',
                [self::tenant('a'), self::entity('main')],
            ));
            self::assertSame(1, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM condor_document_record WHERE id = ?', [$legacy->id()],
            ), 'Legacy source is preserved but not exposed by the branch SQL join.');
            self::assertSame(1, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM condor_document_audit WHERE record_ref = ? AND action = ?',
                [$legacy->recordRef(), 'CREADA'],
            ), 'Branch reads never audit or expose unlinked legacy rows.');
        } finally {
            $db->rollBack();
        }
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM condor_document_branch_link WHERE tenant_ref = ?', [self::tenant('a')],
        ));
    }

    public function testUnauthorizedDuplicateAndTamperedEventsFailWithoutResidualAudit(): void
    {
        $db = $this->database();
        $repo = new BranchDocumentRecordRepository($db);
        $db->beginTransaction();
        try {
            $draft = self::draft($repo, 'a', 'main', 'north');
            self::reject(static fn () => $repo->createDraft(self::tenant('a'), self::entity('main'),
                self::branch('south'), 'fixture:dossier/other', 'fixture:version/one',
                'INTERNA', self::actor(), 'editor', false));
            self::reject(static fn () => $repo->createDraft(self::tenant('a'), self::entity('main'),
                self::branch('north'), 'fixture:dossier/other', 'fixture:version/one',
                'INTERNA', self::actor(), 'auditor', true));
            self::reject(static fn () => $repo->forBranch(self::tenant('a'), self::entity('main'),
                self::branch('north'), 'fixture:actor/auditor', 'auditor', false));
            self::reject(static fn () => $repo->forBranch(self::tenant('a'), self::entity('main'),
                'fixture:branch/invalid12345678', 'fixture:actor/auditor', 'auditor', true));
            try {
                self::draft($repo, 'a', 'main', 'north');
                self::fail('Duplicate external reference must fail.');
            } catch (UniqueConstraintViolationException) {
                self::assertSame(1, (int) $db->fetchOne(
                    'SELECT COUNT(*) FROM condor_document_record WHERE tenant_ref = ?', [self::tenant('a')],
                ));
            }
            self::assertSame(1, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM condor_document_audit WHERE tenant_ref = ?', [self::tenant('a')],
            ), 'The failed link insert must roll back its document and write audit.');

            $db->executeStatement('UPDATE condor_document_record SET fingerprint = ? WHERE id = ?',
                [str_repeat('0', 64), $draft->id()]);
            self::reject(static fn () => $repo->forBranch(self::tenant('a'), self::entity('main'),
                self::branch('north'), 'fixture:actor/auditor', 'auditor', true));
            self::assertSame(1, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM condor_document_audit WHERE tenant_ref = ?', [self::tenant('a')],
            ));
        } finally {
            $db->rollBack();
        }
    }

    public function testDatabaseForeignKeyRejectsCrossTenantRebinding(): void
    {
        $db = $this->database();
        $repo = new BranchDocumentRecordRepository($db);
        $db->beginTransaction();
        try {
            $draft = self::draft($repo, 'a', 'main', 'north');
            $foreign = self::draft($repo, 'b', 'main', 'north');
            try {
                $db->executeStatement('UPDATE condor_document_branch_link SET record_ref = ? '
                    . 'WHERE tenant_ref = ? AND legal_entity_ref = ? AND branch_ref = ?',
                    [$foreign->recordRef(), self::tenant('a'), self::entity('main'), self::branch('north')]);
                self::fail('The composite FK must reject a foreign internal dossier.');
            } catch (ForeignKeyConstraintViolationException) {
                self::assertSame($draft->recordRef(), $db->fetchOne(
                    'SELECT record_ref FROM condor_document_branch_link WHERE tenant_ref = ? AND branch_ref = ?',
                    [self::tenant('a'), self::branch('north')],
                ));
            }
        } finally {
            $db->rollBack();
        }
    }

    private function database(): Connection
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $db = $em->getConnection();
        self::assertSame('pdo_mysql', $db->getParams()['driver'] ?? null,
            'Acceptance requires a disposable real MariaDB instance.');
        return $db;
    }

    private static function draft(BranchDocumentRecordRepository $repo,
        string $tenant, string $entity, string $branch): DocumentRecord
    {
        return $repo->createDraft(self::tenant($tenant), self::entity($entity),
            self::branch($branch), 'fixture:dossier/shared', 'fixture:version/one',
            'INTERNA', self::actor(), 'editor', true);
    }

    private static function tenant(string $id): string { return 'fixture:tenant/'.$id; }
    private static function entity(string $id): string { return 'fixture:legal-entity/'.$id; }
    private static function branch(string $id): string { return 'fixture:branch/'.$id; }
    private static function actor(): string { return 'fixture:actor/editor'; }

    private static function reject(callable $action): void
    {
        try {
            $action();
            self::fail('The operation must deny this input.');
        } catch (DomainException $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }
}
