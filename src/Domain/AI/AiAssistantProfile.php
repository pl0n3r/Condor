<?php

declare(strict_types=1);

namespace App\Domain\AI;

use DomainException;

final readonly class AiAssistantProfile
{
    private const GOALS = ['support', 'sales'];
    private const TONES = ['neutral', 'warm', 'concise'];
    private const HANDOFF_MODES = ['required_on_unknown', 'always_available'];

    /** @var list<string> */
    private array $goals;

    /** @param list<string> $goals */
    private function __construct(
        private string $tenantId,
        private string $assistantRef,
        array $goals,
        private string $tone,
        private string $handoffMode,
    ) {
        $this->goals = $goals;
    }

    /** @param array<string, mixed> $profile */
    public static function fromArray(array $profile): self
    {
        $keys = array_keys($profile);
        sort($keys);
        if ($keys !== ['assistant_ref', 'goals', 'handoff_mode', 'tenant_id', 'tone']) {
            throw new DomainException('Blueprint de asistente IA no canónico.');
        }

        $tenantId = $profile['tenant_id'];
        $assistantRef = $profile['assistant_ref'];
        $goals = $profile['goals'];
        $tone = $profile['tone'];
        $handoffMode = $profile['handoff_mode'];

        if (
            !is_string($tenantId)
            || !is_string($assistantRef)
            || !is_array($goals)
            || !is_string($tone)
            || !is_string($handoffMode)
        ) {
            throw new DomainException('Blueprint de asistente IA inválido.');
        }

        return new self(
            self::normalizeTenantId($tenantId),
            self::normalizeAssistantRef($assistantRef),
            self::normalizeGoals($goals),
            self::normalizeEnum($tone, self::TONES, 'Tone de asistente IA inválido.'),
            self::normalizeEnum(
                $handoffMode,
                self::HANDOFF_MODES,
                'Handoff mode de asistente IA inválido.',
            ),
        );
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function assistantRef(): string
    {
        return $this->assistantRef;
    }

    /** @return list<string> */
    public function goals(): array
    {
        return $this->goals;
    }

    public function tone(): string
    {
        return $this->tone;
    }

    public function handoffMode(): string
    {
        return $this->handoffMode;
    }

    /**
     * @return array{
     *     tenant_ref:string,
     *     assistant_ref:string,
     *     goals:list<string>,
     *     tone:string,
     *     handoff_mode:string
     * }
     */
    public function snapshot(): array
    {
        return [
            'tenant_ref' => 'tenant:' . $this->tenantId,
            'assistant_ref' => $this->assistantRef,
            'goals' => $this->goals,
            'tone' => $this->tone,
            'handoff_mode' => $this->handoffMode,
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
            throw new DomainException('Tenant de asistente IA inválido.');
        }

        return $tenantId;
    }

    private static function normalizeAssistantRef(string $assistantRef): string
    {
        $assistantRef = trim($assistantRef);
        if (
            strlen($assistantRef) > 138
            || preg_match('/^assistant:[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $assistantRef) !== 1
        ) {
            throw new DomainException('Assistant ref inválida.');
        }

        return $assistantRef;
    }

    /**
     * @param array<mixed> $goals
     * @return list<string>
     */
    private static function normalizeGoals(array $goals): array
    {
        if (!array_is_list($goals) || $goals === [] || count($goals) > count(self::GOALS)) {
            throw new DomainException('Goals de asistente IA inválidos.');
        }

        $selected = [];
        foreach ($goals as $goal) {
            if (!is_string($goal)) {
                throw new DomainException('Goal de asistente IA inválido.');
            }

            $goal = strtolower(trim($goal));
            if (!in_array($goal, self::GOALS, true) || in_array($goal, $selected, true)) {
                throw new DomainException('Goal de asistente IA inválido.');
            }

            $selected[] = $goal;
        }

        return array_values(array_filter(
            self::GOALS,
            static fn (string $goal): bool => in_array($goal, $selected, true),
        ));
    }

    /**
     * @param list<string> $allowed
     */
    private static function normalizeEnum(string $value, array $allowed, string $error): string
    {
        $value = strtolower(trim($value));
        if (!in_array($value, $allowed, true)) {
            throw new DomainException($error);
        }

        return $value;
    }
}
