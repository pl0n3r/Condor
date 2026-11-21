<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Documents\Entity\DocumentRecord;
use App\Infrastructure\Persistence\DocumentRecordRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #1927 / #1621: MariaDB real, only synthetic M08 record events.
 * No uploads, legal approvals, deletion, retention, external I/O or live data.
 */
final class GestDocumentRecordSqlTest extends KernelTestCase
{
    public function testMariaDbVersionChainAuditAndRollback(): void
    {
        $db = $this->database();
        $repo = new DocumentRecordRepository($db);
        $first = self::draft('alpha');
        $review = $first->sendToReview('fixture:actor/reviewer', true);
        $foreign = self::draft('beta');
        $db->beginTransaction();
        try {
            $repo->append($first, $first->tenantRef(), $first->legalEntityRef(),
                $first->actorRef(), 'editor', true);
            $repo->append($review, $review->tenantRef(), $review->legalEntityRef(),
                $review->actorRef(), 'revisor', true);
            $repo->append($foreign, $foreign->tenantRef(), $foreign->legalEntityRef(),
                $foreign->actorRef(), 'editor', true);

            $own = $repo->forCompany($first->tenantRef(), $first->legalEntityRef(),
                'fixture:actor/auditor', 'auditor', true);
            $other = $repo->forCompany($foreign->tenantRef(), $foreign->legalEntityRef(),
                'fixture:actor/auditor', 'auditor', true);

            self::assertCount(2, $own);
            self::assertCount(1, $other);
            self::assertSame([1, 2], array_map(
                static fn (array $row): int => (int) $row['version'], $own,
            ));
            self::assertSame([$first->id(), $review->id()], array_column($own, 'id'));
            self::assertNull($own[0]['previous_id']);
            self::assertSame($first->id(), $own[1]['previous_id']);
            self::assertSame(['CREADA', 'REVISION_EXPLICITA'],
                array_column($own, 'audit_action'));
            self::assertSame('BORRADOR', $own[0]['state']);
            self::assertSame('REQUIERE_REVISION', $own[1]['state']);
            self::assertSame($first->snapshot()['fingerprint'], $own[0]['fingerprint']);
            self::assertSame($review->snapshot()['fingerprint'], $own[1]['fingerprint']);
            self::assertNotSame($own[0]['fingerprint'], $own[1]['fingerprint']);
            self::assertSame($first->tenantRef(), $own[0]['tenant_ref']);
            self::assertSame($foreign->tenantRef(), $other[0]['tenant_ref']);
            self::assertSame(4, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM condor_document_audit WHERE tenant_ref = ?',
                [$first->tenantRef()],
            ), 'Two writes plus two record-level reads require four authentic audit rows.');
            self::assertSame(2, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM condor_document_record WHERE tenant_ref = ?',
                [$first->tenantRef()],
            ));
        } finally {
            $db->rollBack();
        }
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM condor_document_record WHERE id = ?', [$first->id()],
        ));
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM condor_document_audit WHERE tenant_ref = ?',
            [$first->tenantRef()],
        ));
    }

    public function testStaleCrossTenantRoleAndSelfReviewFailClosed(): void
    {
        $db = $this->database();
        $repo = new DocumentRecordRepository($db);
        $first = self::draft('scope');
        $review = $first->sendToReview('fixture:actor/reviewer', true);
        self::reject(static fn (): DocumentRecord => $first->sendToReview(
            $first->actorRef(), true,
        ));
        self::reject(static fn (): DocumentRecord => $first->sendToReview(
            'fixture:actor/reviewer', false,
        ));
        self::reject(static fn (): DocumentRecord => DocumentRecord::open(
            'real-tenant', 'fixture:legal-entity/main', 'fixture:dossier/record',
            'fixture:version/one', 'INTERNA', 'fixture:actor/editor',
        ));

        $db->beginTransaction();
        try {
            self::reject(static fn () => $repo->append($review,
                $review->tenantRef(), $review->legalEntityRef(),
                $review->actorRef(), 'revisor', true));
            self::reject(static fn () => $repo->append($first,
                'fixture:tenant/foreign', $first->legalEntityRef(),
                $first->actorRef(), 'editor', true));
            self::reject(static fn () => $repo->append($first,
                $first->tenantRef(), $first->legalEntityRef(),
                $first->actorRef(), 'auditor', true));
            self::reject(static fn () => $repo->append($first,
                $first->tenantRef(), $first->legalEntityRef(),
                $first->actorRef(), 'editor', false));

            $repo->append($first, $first->tenantRef(), $first->legalEntityRef(),
                $first->actorRef(), 'editor', true);
            self::reject(static fn () => $repo->append($first,
                $first->tenantRef(), $first->legalEntityRef(),
                $first->actorRef(), 'editor', true));
            self::reject(static fn () => $repo->forCompany(
                $first->tenantRef(), $first->legalEntityRef(),
                'fixture:actor/auditor', 'auditor', false));
            self::assertSame(1, (int) $db->fetchOne(
                'SELECT COUNT(*) FROM condor_document_record WHERE tenant_ref = ?',
                [$first->tenantRef()],
            ));
        } finally {
            $db->rollBack();
        }
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM condor_document_record WHERE id = ?', [$first->id()],
        ));
    }

    public function testPersistedFingerprintTamperingIsRejected(): void
    {
        $db = $this->database();
        $repo = new DocumentRecordRepository($db);
        $first = self::draft('tamper');
        $db->beginTransaction();
        try {
            $repo->append($first, $first->tenantRef(), $first->legalEntityRef(),
                $first->actorRef(), 'editor', true);
            $db->executeStatement(
                'UPDATE condor_document_record SET fingerprint = :fingerprint WHERE id = :id',
                ['fingerprint' => str_repeat('0', 64), 'id' => $first->id()],
            );
            self::reject(static fn (): array => $repo->forCompany(
                $first->tenantRef(), $first->legalEntityRef(),
                'fixture:actor/auditor', 'auditor', true,
            ));
        } finally {
            $db->rollBack();
        }
        self::assertSame(0, (int) $db->fetchOne(
            'SELECT COUNT(*) FROM condor_document_record WHERE id = ?', [$first->id()],
        ));
    }

    private function database(): Connection
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $db = $em->getConnection();
        self::assertSame('pdo_mysql', $db->getParams()['driver'] ?? null,
            'This acceptance requires the real disposable MariaDB, never SQLite.');
        return $db;
    }

    private static function draft(string $tenant): DocumentRecord
    {
        return DocumentRecord::open(
            'fixture:tenant/'.$tenant, 'fixture:legal-entity/main',
            'fixture:dossier/record', 'fixture:version/one',
            'INTERNA', 'fixture:actor/editor',
        );
    }

    private static function reject(callable $operation): void
    {
        try {
            $operation();
            self::fail('Missing fail-closed DomainException.');
        } catch (DomainException $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }
}
