<?php

declare(strict_types=1);

namespace App\Tests\Application\Documents;

use App\Application\Documents\DocumentApiService;
use DomainException;
use PHPUnit\Framework\TestCase;

/** Fixtures only: does not certify legal signatures, live HTTP or React UI. */
final class GestM08VerificationTest extends TestCase
{
    private const A = 'fixture-tenant-a|company-a|branch-a';
    private const B = 'fixture-tenant-b|company-a|branch-a';
    private const REF = 'fixture:doc/alpha';

    public function testS05EvidenceCannotBecomeLegalSignatureOrCrossTenantWrite(): void
    {
        $api = new DocumentApiService();
        $created = $api->create([], self::A, self::REF, 'RESERVADA');
        $state = $created['state'];
        $this->rejects(static fn () => $api->attachEvidence(
            $state, self::B, self::REF, 'fixture:evidence/demo',
        ));
        $this->rejects(static fn () => $api->attachEvidence(
            $state, self::A, self::REF, 'someone@example.com',
        ));
        $result = $api->attachEvidence($state, self::A, self::REF, 'fixture:evidence/demo');
        self::assertTrue($result['changed']);
        self::assertSame('fixture:evidence/demo', $api->detail($result['state'], self::A, self::REF)['evidence_ref']);
        self::assertFalse($api->export($result['state'], self::A)['legal_signature']);
        self::assertFalse($api->export($result['state'], self::A)['can_close']);
        self::assertFalse($api->attachEvidence(
            $result['state'], self::A, self::REF, 'fixture:evidence/demo',
        )['changed']);
        $this->rejects(static fn () => $api->attachEvidence(
            $result['state'], self::A, self::REF, 'fixture:evidence/other',
        ));
    }

    public function testS07ExportIsDeterministicMetadataOnlyAndTenantScoped(): void
    {
        $api = new DocumentApiService();
        $state = $api->create([], self::A, 'fixture:doc/zeta', 'PUBLICA')['state'];
        $state = $api->create($state, self::A, self::REF, 'INTERNA')['state'];
        $state = $api->create($state, self::B, 'fixture:doc/foreign', 'PUBLICA')['state'];
        $export = $api->export($state, self::A);
        self::assertSame(['fixture:doc/alpha', 'fixture:doc/zeta'], array_column($export['documents'], 'reference'));
        self::assertSame([], $api->export($state, 'unknown-scope')['documents']);
        self::assertCount(1, $api->export($state, self::B)['documents']);
        self::assertSame($export, $api->export($state, self::A));
        self::assertTrue($export['synthetic']);
        self::assertFalse($export['can_close']);
    }

    public function testS08TraceIsAppendOnlyAndScopedToDocument(): void
    {
        $api = new DocumentApiService();
        $state = $api->create([], self::A, self::REF, 'PUBLICA')['state'];
        $state = $api->attachEvidence($state, self::A, self::REF, 'fixture:evidence/demo')['state'];
        $state = $api->requestReview($state, self::A, self::REF)['state'];
        $trace = $api->trace($state, self::A, self::REF);
        self::assertSame(['CREADO', 'EVIDENCIA_ADJUNTADA', 'REVISION_SOLICITADA'], array_column($trace['events'], 'action'));
        self::assertSame([1, 2, 3], array_column($trace['events'], 'sequence'));
        self::assertSame('PENDIENTE_REVISION', $api->detail($state, self::A, self::REF)['status']);
        self::assertFalse($trace['can_close']);
        $this->rejects(static fn () => $api->trace($state, self::B, self::REF));
        $tampered = $state;
        $tampered['trace:'.self::A][self::REF][0]['sequence'] = 99;
        $this->rejects(static fn () => $api->trace($tampered, self::A, self::REF));
        $tampered = $state;
        $tampered['trace:'.self::A][self::REF][0]['action'] = 'APROBADO';
        $this->rejects(static fn () => $api->trace($tampered, self::A, self::REF));
        $this->rejects(static fn () => $api->attachEvidence(
            $state, self::A, self::REF, 'fixture:evidence/later',
        ));
    }

    public function testHttpRoutesAreConstructionOnlyAndEnforcePermissionsServerSide(): void
    {
        $controller = file_get_contents(__DIR__.'/../../../../src/Http/Controller/DocumentsController.php');
        self::assertIsString($controller);
        foreach ([
            "name: 'api_documents_evidence'", "name: 'api_documents_export'",
            "name: 'api_documents_trace'", "'settings.view'", "'settings.update'",
            '$this->authorizedBranchScope($branchId,',
            '$this->requireCsrf($request);', '$this->constructionOnly();',
            "hash('sha256',", "private const SESSION_KEY",
            "'Content-Disposition'", "'private, no-store'",
        ] as $required) {
            self::assertStringContainsString($required, $controller);
        }
        self::assertSame(7, substr_count($controller, '$this->authorizedBranchScope($branchId,'));
        self::assertSame(2, substr_count($controller, '$this->requireDocumentWriteCsrf($request);'));
        self::assertSame(2, substr_count($controller, '$this->requireCsrf($request);'));
        self::assertSame(4, substr_count($controller, '$this->audit('));
        // Source contract only; real HTTP negatives and React UI require a scoped follow-up.
    }

    private function rejects(callable $action): void
    {
        try {
            $action();
            self::fail('Se aceptó una operación documental inválida.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}
