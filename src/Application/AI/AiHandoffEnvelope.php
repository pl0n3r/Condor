<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Domain\AI\AiTenantContext;
use DomainException;

final readonly class AiHandoffEnvelope
{
    private const ROUTES = ['knowledge', 'tool', 'none'];
    private const MAX_EVIDENCE_REFS = 32;

    /** @var 'knowledge'|'tool'|'none' */
    private string $route;

    /** @var list<string> */
    private array $evidenceRefs;

    /**
     * @param 'knowledge'|'tool'|'none' $route
     * @param list<string> $evidenceRefs
     */
    private function __construct(
        private string $tenantRef,
        string $route,
        private string $reason,
        array $evidenceRefs,
    ) {
        $this->route = $route;
        $this->evidenceRefs = $evidenceRefs;
    }

    /** @param array<string, mixed> $envelope */
    public static function fromArray(AiTenantContext $context, array $envelope): self
    {
        $keys = array_keys($envelope);
        sort($keys);
        if ($keys !== ['evidence_refs', 'reason', 'route', 'tenant_ref']) {
            throw new DomainException('Envelope de handoff IA no canónico.');
        }

        $tenantRef = $envelope['tenant_ref'];
        $route = $envelope['route'];
        $reason = $envelope['reason'];
        $evidenceRefs = $envelope['evidence_refs'];

        if (
            !is_string($tenantRef)
            || !is_string($route)
            || !is_string($reason)
            || !is_array($evidenceRefs)
        ) {
            throw new DomainException('Envelope de handoff IA inválido.');
        }

        $expectedTenantRef = 'tenant:' . $context->tenantId();
        if ($tenantRef !== $expectedTenantRef) {
            throw new DomainException('Tenant ref del handoff IA no coincide con el contexto.');
        }

        $route = strtolower(trim($route));
        if (!in_array($route, self::ROUTES, true)) {
            throw new DomainException('Route del handoff IA inválido.');
        }

        $reason = strtolower(trim($reason));
        if (
            strlen($reason) > 96
            || preg_match('/^[a-z][a-z0-9_]*$/D', $reason) !== 1
        ) {
            throw new DomainException('Reason del handoff IA inválido.');
        }

        return new self(
            $tenantRef,
            $route,
            $reason,
            self::normalizeEvidenceRefs($evidenceRefs),
        );
    }

    /**
     * @return array{
     *     tenant_ref:string,
     *     route:'knowledge'|'tool'|'none',
     *     reason:string,
     *     evidence_refs:list<string>
     * }
     */
    public function snapshot(): array
    {
        return [
            'tenant_ref' => $this->tenantRef,
            'route' => $this->route,
            'reason' => $this->reason,
            'evidence_refs' => $this->evidenceRefs,
        ];
    }

    /**
     * @param array<mixed> $refs
     * @return list<string>
     */
    private static function normalizeEvidenceRefs(array $refs): array
    {
        if (!array_is_list($refs) || count($refs) > self::MAX_EVIDENCE_REFS) {
            throw new DomainException('Evidence refs del handoff IA inválidas.');
        }

        $normalized = [];
        foreach ($refs as $ref) {
            if (!is_string($ref)) {
                throw new DomainException('Evidence ref del handoff IA inválida.');
            }

            $ref = trim($ref);
            if (
                strlen($ref) > 169
                || preg_match('/^(?:evidence|knowledge):[A-Za-z0-9][A-Za-z0-9._-]{0,159}$/D', $ref) !== 1
            ) {
                throw new DomainException('Evidence ref del handoff IA inválida.');
            }

            $normalized[] = $ref;
        }

        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new DomainException('Evidence refs del handoff IA duplicadas.');
        }

        return $normalized;
    }
}
