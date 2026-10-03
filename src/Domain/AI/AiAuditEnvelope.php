<?php

declare(strict_types=1);

namespace App\Domain\AI;

use DateTimeImmutable;
use DomainException;

final readonly class AiAuditEnvelope
{
    private const OUTCOMES = ['success', 'denied', 'failure'];

    private function __construct(
        private string $tenantRef,
        private string $toolRef,
        private string $outcome,
        private string $evidenceRef,
        private string $timestamp,
    ) {
    }

    /** @param array<string, mixed> $envelope */
    public static function fromArray(array $envelope, AiToolPolicy $policy): self
    {
        $keys = array_keys($envelope);
        sort($keys);
        if ($keys !== ['evidence_ref', 'outcome', 'tenant_ref', 'timestamp', 'tool_ref']) {
            throw new DomainException('Envelope de auditoría IA no canónico.');
        }

        $tenantRef = $envelope['tenant_ref'];
        $toolRef = $envelope['tool_ref'];
        $outcome = $envelope['outcome'];
        $evidenceRef = $envelope['evidence_ref'];
        $timestamp = $envelope['timestamp'];

        if (
            !is_string($tenantRef)
            || !is_string($toolRef)
            || !is_string($outcome)
            || !is_string($evidenceRef)
            || !is_string($timestamp)
        ) {
            throw new DomainException('Envelope de auditoría IA inválido.');
        }

        $tenantRef = self::normalizeTenantRef($tenantRef);
        $toolRef = trim($toolRef);
        $policy->risk($toolRef);

        $outcome = strtolower(trim($outcome));
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new DomainException('Outcome de auditoría IA inválido.');
        }

        return new self(
            $tenantRef,
            $toolRef,
            $outcome,
            self::normalizeEvidenceRef($evidenceRef),
            self::normalizeTimestamp($timestamp),
        );
    }

    public function tenantRef(): string
    {
        return $this->tenantRef;
    }

    public function toolRef(): string
    {
        return $this->toolRef;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function evidenceRef(): string
    {
        return $this->evidenceRef;
    }

    public function timestamp(): string
    {
        return $this->timestamp;
    }

    /**
     * @return array{
     *     tenant_ref:string,
     *     tool_ref:string,
     *     outcome:string,
     *     evidence_ref:string,
     *     timestamp:string
     * }
     */
    public function snapshot(): array
    {
        return [
            'tenant_ref' => $this->tenantRef,
            'tool_ref' => $this->toolRef,
            'outcome' => $this->outcome,
            'evidence_ref' => $this->evidenceRef,
            'timestamp' => $this->timestamp,
        ];
    }

    private static function normalizeTenantRef(string $tenantRef): string
    {
        $tenantRef = trim($tenantRef);
        if (
            strlen($tenantRef) > 135
            || preg_match('/^tenant:[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $tenantRef) !== 1
        ) {
            throw new DomainException('Tenant ref de auditoría IA inválida.');
        }

        return $tenantRef;
    }

    private static function normalizeEvidenceRef(string $evidenceRef): string
    {
        $evidenceRef = trim($evidenceRef);
        if (
            strlen($evidenceRef) > 169
            || preg_match('/^evidence:[A-Za-z0-9][A-Za-z0-9._-]{0,159}$/D', $evidenceRef) !== 1
        ) {
            throw new DomainException('Evidence ref de auditoría IA inválida.');
        }

        return $evidenceRef;
    }

    private static function normalizeTimestamp(string $timestamp): string
    {
        $timestamp = trim($timestamp);
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $timestamp);
        if (
            $parsed === false
            || $parsed->format('Y-m-d\TH:i:sP') !== $timestamp
        ) {
            throw new DomainException('Timestamp de auditoría IA inválido.');
        }

        return $timestamp;
    }
}
