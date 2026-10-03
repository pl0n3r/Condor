<?php

declare(strict_types=1);

namespace App\Domain\AI;

use DomainException;

final readonly class AiTenantContext
{
    private const MAX_KNOWLEDGE_REFS = 32;

    /** @var list<string> */
    private array $knowledgeRefs;

    private function __construct(
        private string $tenantId,
        private string $tool,
        array $knowledgeRefs,
    ) {
        $this->knowledgeRefs = $knowledgeRefs;
    }

    /**
     * @param array{tenant_id:mixed,tool:mixed,knowledge_refs:mixed} $context
     */
    public static function fromArray(array $context, AiToolPolicy $policy): self
    {
        $keys = array_keys($context);
        sort($keys);
        if ($keys !== ['knowledge_refs', 'tenant_id', 'tool']) {
            throw new DomainException('Contexto de IA no canónico.');
        }

        $tenantId = $context['tenant_id'];
        $tool = $context['tool'];
        $knowledgeRefs = $context['knowledge_refs'];

        if (!is_string($tenantId) || !is_string($tool) || !is_array($knowledgeRefs)) {
            throw new DomainException('Contexto de IA inválido.');
        }

        $tenantId = self::normalizeTenantId($tenantId);
        $tool = trim($tool);
        $policy->risk($tool);

        return new self(
            $tenantId,
            $tool,
            self::normalizeKnowledgeRefs($knowledgeRefs),
        );
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function tool(): string
    {
        return $this->tool;
    }

    /** @return list<string> */
    public function knowledgeRefs(): array
    {
        return $this->knowledgeRefs;
    }

    public function rebindTenant(string $tenantId): self
    {
        if (self::normalizeTenantId($tenantId) !== $this->tenantId) {
            throw new DomainException('Rebind cross-tenant no autorizado.');
        }

        return $this;
    }

    /**
     * @return array{tenant_id:string,tool:string,knowledge_refs:list<string>}
     */
    public function snapshot(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'tool' => $this->tool,
            'knowledge_refs' => $this->knowledgeRefs,
        ];
    }

    private static function normalizeTenantId(string $tenantId): string
    {
        $tenantId = trim($tenantId);
        if (
            $tenantId === ''
            || strlen($tenantId) > 128
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $tenantId) !== 1
        ) {
            throw new DomainException('Tenant de contexto IA inválido.');
        }

        return $tenantId;
    }

    /**
     * @param array<mixed> $refs
     * @return list<string>
     */
    private static function normalizeKnowledgeRefs(array $refs): array
    {
        if (count($refs) > self::MAX_KNOWLEDGE_REFS || !array_is_list($refs)) {
            throw new DomainException('Knowledge scope de IA inválido.');
        }

        $normalized = [];
        foreach ($refs as $ref) {
            if (
                !is_string($ref)
                || strlen($ref) > 160
                || preg_match('/^knowledge:[A-Za-z0-9][A-Za-z0-9._-]*$/D', $ref) !== 1
            ) {
                throw new DomainException('Knowledge ref de IA inválida.');
            }

            $normalized[] = $ref;
        }

        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new DomainException('Knowledge scope de IA duplicado.');
        }

        return $normalized;
    }
}
