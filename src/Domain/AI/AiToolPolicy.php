<?php

declare(strict_types=1);

namespace App\Domain\AI;

use DomainException;

final class AiToolPolicy
{
    public const READ_ONLY = 'read_only';
    public const REVERSIBLE_WRITE = 'reversible_write';
    public const SENSITIVE = 'sensitive';

    /** @var array<string, self::READ_ONLY|self::REVERSIBLE_WRITE|self::SENSITIVE> */
    private const TOOLS = [
        'catalog.read' => self::READ_ONLY,
        'inventory.read' => self::READ_ONLY,
        'knowledge.read' => self::READ_ONLY,
        'content.draft.update' => self::REVERSIBLE_WRITE,
        'settings.draft.update' => self::REVERSIBLE_WRITE,
        'identity.permission.change' => self::SENSITIVE,
    ];

    private const FORBIDDEN_SEGMENTS = [
        'sql',
        'db',
        'database',
        'provider',
        'model',
        'channel',
        'secret',
        'token',
        'credential',
        'credentials',
    ];

    /**
     * @return self::READ_ONLY|self::REVERSIBLE_WRITE|self::SENSITIVE
     */
    public function risk(string $tool): string
    {
        $tool = $this->normalize($tool);
        $risk = self::TOOLS[$tool] ?? null;
        if ($risk === null) {
            throw new DomainException('Tool de IA no autorizada.');
        }

        return $risk;
    }

    public function allowsAutonomousExecution(string $tool): bool
    {
        try {
            return $this->risk($tool) !== self::SENSITIVE;
        } catch (DomainException) {
            return false;
        }
    }

    /**
     * @return array<string, self::READ_ONLY|self::REVERSIBLE_WRITE|self::SENSITIVE>
     */
    public function allowlist(): array
    {
        return self::TOOLS;
    }

    private function normalize(string $tool): string
    {
        $tool = strtolower(trim($tool));
        if (
            $tool === ''
            || preg_match(
                '/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9_]*)+$/D',
                $tool,
            ) !== 1
        ) {
            throw new DomainException('Identificador de tool de IA inválido.');
        }

        $segments = explode('.', $tool);
        foreach ($segments as $segment) {
            if (in_array($segment, self::FORBIDDEN_SEGMENTS, true)) {
                throw new DomainException('Tool de IA fuera de la frontera autorizada.');
            }
        }

        return $tool;
    }
}
