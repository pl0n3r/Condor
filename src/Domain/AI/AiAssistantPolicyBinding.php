<?php

declare(strict_types=1);

namespace App\Domain\AI;

use DomainException;

final readonly class AiAssistantPolicyBinding
{
    private const MAX_TOOLS = 32;
    private const MAX_KNOWLEDGE_REFS = 32;

    /** @var list<string> */
    private array $toolRefs;

    /** @var list<string> */
    private array $knowledgeRefs;

    /**
     * @param list<string> $toolRefs
     * @param list<string> $knowledgeRefs
     */
    private function __construct(
        private string $tenantRef,
        private string $assistantRef,
        array $toolRefs,
        array $knowledgeRefs,
    ) {
        $this->toolRefs = $toolRefs;
        $this->knowledgeRefs = $knowledgeRefs;
    }

    /** @param array<string, mixed> $binding */
    public static function fromArray(
        AiAssistantProfile $profile,
        AiToolPolicy $policy,
        array $binding,
    ): self {
        $keys = array_keys($binding);
        sort($keys);
        if ($keys !== ['assistant_ref', 'knowledge_refs', 'tenant_id', 'tools']) {
            throw new DomainException('Binding de asistente IA no canónico.');
        }

        $tenantId = $binding['tenant_id'];
        $assistantRef = $binding['assistant_ref'];
        $tools = $binding['tools'];
        $knowledgeRefs = $binding['knowledge_refs'];

        if (
            !is_string($tenantId)
            || !is_string($assistantRef)
            || !is_array($tools)
            || !is_array($knowledgeRefs)
        ) {
            throw new DomainException('Binding de asistente IA inválido.');
        }

        $tenantId = trim($tenantId);
        $assistantRef = trim($assistantRef);

        if ($tenantId !== $profile->tenantId()) {
            throw new DomainException('Binding cross-tenant de asistente IA no autorizado.');
        }

        if ($assistantRef !== $profile->assistantRef()) {
            throw new DomainException('Assistant ref del binding no coincide con el profile.');
        }

        return new self(
            'tenant:' . $tenantId,
            $assistantRef,
            self::normalizeTools($tools, $policy),
            self::normalizeKnowledgeRefs($knowledgeRefs),
        );
    }

    public function tenantRef(): string
    {
        return $this->tenantRef;
    }

    public function assistantRef(): string
    {
        return $this->assistantRef;
    }

    /** @return list<string> */
    public function toolRefs(): array
    {
        return $this->toolRefs;
    }

    /** @return list<string> */
    public function knowledgeRefs(): array
    {
        return $this->knowledgeRefs;
    }

    /**
     * @return array{
     *     tenant_ref:string,
     *     assistant_ref:string,
     *     tool_refs:list<string>,
     *     knowledge_refs:list<string>
     * }
     */
    public function snapshot(): array
    {
        return [
            'tenant_ref' => $this->tenantRef,
            'assistant_ref' => $this->assistantRef,
            'tool_refs' => $this->toolRefs,
            'knowledge_refs' => $this->knowledgeRefs,
        ];
    }

    /**
     * @param array<mixed> $tools
     * @return list<string>
     */
    private static function normalizeTools(array $tools, AiToolPolicy $policy): array
    {
        if (!array_is_list($tools) || count($tools) > self::MAX_TOOLS) {
            throw new DomainException('Tools del binding de asistente IA inválidas.');
        }

        $selected = [];
        foreach ($tools as $tool) {
            if (!is_string($tool)) {
                throw new DomainException('Tool del binding de asistente IA inválida.');
            }

            $tool = strtolower(trim($tool));
            $risk = $policy->risk($tool);
            if ($risk === AiToolPolicy::SENSITIVE || in_array($tool, $selected, true)) {
                throw new DomainException('Tool del binding de asistente IA no autorizada.');
            }

            $selected[] = $tool;
        }

        $canonical = [];
        foreach (array_keys($policy->allowlist()) as $tool) {
            if (in_array($tool, $selected, true)) {
                $canonical[] = $tool;
            }
        }

        return $canonical;
    }

    /**
     * @param array<mixed> $refs
     * @return list<string>
     */
    private static function normalizeKnowledgeRefs(array $refs): array
    {
        if (!array_is_list($refs) || count($refs) > self::MAX_KNOWLEDGE_REFS) {
            throw new DomainException('Knowledge refs del binding de asistente IA inválidas.');
        }

        $normalized = [];
        foreach ($refs as $ref) {
            if (!is_string($ref)) {
                throw new DomainException('Knowledge ref del binding de asistente IA inválida.');
            }

            $ref = trim($ref);
            if (
                strlen($ref) > 170
                || preg_match('/^knowledge:[A-Za-z0-9][A-Za-z0-9._-]{0,159}$/D', $ref) !== 1
                || in_array($ref, $normalized, true)
            ) {
                throw new DomainException('Knowledge ref del binding de asistente IA inválida.');
            }

            $normalized[] = $ref;
        }

        sort($normalized);

        return $normalized;
    }
}
