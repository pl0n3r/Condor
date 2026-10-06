<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DateTimeImmutable;
use DomainException;

final readonly class AiDraftWriteRuntime
{
    private const TOOLS = ['content.draft.update', 'settings.draft.update'];

    /**
     * @param array<string, mixed> $turn
     * @param array<string, mixed> $handlers
     * @return array<string, mixed>
     */
    public static function turn(
        AiTenantContext $context,
        AiToolPolicy $policy,
        array $turn,
        DateTimeImmutable $at,
        array $handlers,
        ?AiToolReplayGuard $replayGuard = null,
    ): array {
        try {
            $drafts = AiDraftWriteRegistry::fromArray($policy, $handlers);
            $registry = self::conversationRegistry($policy, $drafts);
        } catch (DomainException) {
            $registry = AiToolRegistry::fromArray($policy, []);
        }

        return AiConversationCore::turn(
            $context,
            $policy,
            $turn,
            [],
            $at,
            $registry,
            $replayGuard,
        );
    }

    private static function conversationRegistry(
        AiToolPolicy $policy,
        AiDraftWriteRegistry $drafts,
    ): AiToolRegistry {
        $registrations = [];
        foreach (self::TOOLS as $tool) {
            $registrations[] = [
                'tool' => $tool,
                'descriptor' => $drafts->descriptor($tool),
                'handler' => static fn (array $inputs): array => $drafts->execute($tool, $inputs),
            ];
        }

        return AiToolRegistry::fromArray($policy, $registrations);
    }
}
