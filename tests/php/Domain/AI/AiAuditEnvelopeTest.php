<?php

declare(strict_types=1);

namespace App\Tests\Domain\AI;

use App\Domain\AI\AiAuditEnvelope;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiAuditEnvelopeTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function valid(): array
    {
        return [
            'tenant_ref' => 'tenant:acme-1',
            'tool_ref' => 'catalog.read',
            'outcome' => 'success',
            'evidence_ref' => 'evidence:run-42',
            'timestamp' => '2026-01-15T10:30:00+00:00',
        ];
    }

    /**
     * @param array<string, mixed> $override
     */
    private function build(array $override = []): AiAuditEnvelope
    {
        return AiAuditEnvelope::fromArray($override + $this->valid(), new AiToolPolicy());
    }

    public function testBuildsFromACanonicalEnvelopeAndExposesEveryField(): void
    {
        $envelope = $this->build();

        self::assertSame('tenant:acme-1', $envelope->tenantRef());
        self::assertSame('catalog.read', $envelope->toolRef());
        self::assertSame('success', $envelope->outcome());
        self::assertSame('evidence:run-42', $envelope->evidenceRef());
        self::assertSame('2026-01-15T10:30:00+00:00', $envelope->timestamp());
        self::assertSame($this->valid(), $envelope->snapshot());
    }

    public function testSurroundingSpacesAreTrimmedAndTheOutcomeIsLowercased(): void
    {
        $envelope = $this->build([
            'tenant_ref' => '  tenant:acme-1 ',
            'tool_ref' => ' catalog.read ',
            'outcome' => '  DENIED ',
            'evidence_ref' => ' evidence:run-42 ',
            'timestamp' => ' 2026-01-15T10:30:00+00:00 ',
        ]);

        self::assertSame('tenant:acme-1', $envelope->tenantRef());
        self::assertSame('catalog.read', $envelope->toolRef());
        self::assertSame('denied', $envelope->outcome());
        self::assertSame('evidence:run-42', $envelope->evidenceRef());
        self::assertSame('2026-01-15T10:30:00+00:00', $envelope->timestamp());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validOutcomes(): iterable
    {
        yield 'success' => ['success'];
        yield 'denied' => ['denied'];
        yield 'failure' => ['failure'];
    }

    /**
     * @dataProvider validOutcomes
     */
    public function testAcceptsEveryDocumentedOutcome(string $outcome): void
    {
        self::assertSame($outcome, $this->build(['outcome' => $outcome])->outcome());
    }

    public function testRejectsAnUnknownOutcome(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Outcome de auditoría IA inválido.');

        $this->build(['outcome' => 'partial']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function nonCanonicalShapes(): iterable
    {
        yield 'missing key' => [['tenant_ref' => 'tenant:a', 'tool_ref' => 'catalog.read', 'outcome' => 'success', 'evidence_ref' => 'evidence:e']];
        yield 'extra key' => [['extra' => 'x']];
    }

    /**
     * @param array<string, mixed> $shape
     * @dataProvider nonCanonicalShapes
     */
    public function testRejectsAnEnvelopeThatDoesNotHaveExactlyTheCanonicalKeys(array $shape): void
    {
        $envelope = isset($shape['extra']) ? $shape + $this->valid() : $shape;

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Envelope de auditoría IA no canónico.');

        AiAuditEnvelope::fromArray($envelope, new AiToolPolicy());
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function nonStringValues(): iterable
    {
        yield 'tenant_ref' => ['tenant_ref', 12];
        yield 'tool_ref' => ['tool_ref', null];
        yield 'outcome' => ['outcome', true];
        yield 'evidence_ref' => ['evidence_ref', ['evidence:x']];
        yield 'timestamp' => ['timestamp', 1_700_000_000];
    }

    /**
     * @dataProvider nonStringValues
     */
    public function testRejectsAnyFieldThatIsNotAString(string $field, mixed $value): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Envelope de auditoría IA inválido.');

        $this->build([$field => $value]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTenantRefs(): iterable
    {
        yield 'empty' => [''];
        yield 'missing prefix' => ['acme-1'];
        yield 'wrong prefix' => ['evidence:acme-1'];
        yield 'starts with a separator' => ['tenant:-acme'];
        yield 'illegal character' => ['tenant:acme/1'];
        yield 'trailing newline inside' => ["tenant:acme\n1"];
        yield 'longer than 135 characters' => ['tenant:' . str_repeat('a', 129)];
    }

    /**
     * @dataProvider invalidTenantRefs
     */
    public function testRejectsInvalidTenantRefs(string $tenantRef): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Tenant ref de auditoría IA inválida.');

        $this->build(['tenant_ref' => $tenantRef]);
    }

    public function testAcceptsATenantRefAtTheMaximumLength(): void
    {
        $tenantRef = 'tenant:' . str_repeat('a', 128);

        self::assertSame($tenantRef, $this->build(['tenant_ref' => $tenantRef])->tenantRef());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEvidenceRefs(): iterable
    {
        yield 'empty' => [''];
        yield 'missing prefix' => ['run-42'];
        yield 'starts with a separator' => ['evidence:.run'];
        yield 'illegal character' => ['evidence:run 42'];
        yield 'longer than 169 characters' => ['evidence:' . str_repeat('a', 161)];
    }

    /**
     * @dataProvider invalidEvidenceRefs
     */
    public function testRejectsInvalidEvidenceRefs(string $evidenceRef): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Evidence ref de auditoría IA inválida.');

        $this->build(['evidence_ref' => $evidenceRef]);
    }

    public function testAcceptsAnEvidenceRefAtTheMaximumLength(): void
    {
        $evidenceRef = 'evidence:' . str_repeat('a', 160);

        self::assertSame($evidenceRef, $this->build(['evidence_ref' => $evidenceRef])->evidenceRef());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTimestamps(): iterable
    {
        yield 'empty' => [''];
        yield 'date only' => ['2026-01-15'];
        yield 'missing offset' => ['2026-01-15T10:30:00'];
        yield 'not a date' => ['yesterday'];
        yield 'overflowing day that PHP would roll over' => ['2026-02-31T10:30:00+00:00'];
    }

    /**
     * @dataProvider invalidTimestamps
     */
    public function testRejectsTimestampsThatAreNotExactIso8601WithOffset(string $timestamp): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Timestamp de auditoría IA inválido.');

        $this->build(['timestamp' => $timestamp]);
    }

    public function testAcceptsATimestampWithANonUtcOffsetAndKeepsItVerbatim(): void
    {
        self::assertSame(
            '2026-01-15T10:30:00-05:00',
            $this->build(['timestamp' => '2026-01-15T10:30:00-05:00'])->timestamp(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unauthorizedTools(): iterable
    {
        yield 'not in the allowlist' => ['catalog.delete'];
        yield 'forbidden segment' => ['catalog.sql'];
        yield 'malformed identifier' => ['catalog'];
        yield 'empty' => [''];
    }

    /**
     * @dataProvider unauthorizedTools
     */
    public function testRejectsToolsTheAiPolicyDoesNotAuthorize(string $tool): void
    {
        $this->expectException(DomainException::class);

        $this->build(['tool_ref' => $tool]);
    }
}
