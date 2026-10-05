<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DateTimeImmutable;
use DomainException;

final readonly class AiCommerceReadRuntime
{
    private const TOOLS = ['catalog.read', 'inventory.read'];

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
    ): array {
        try {
            $commerce = AiCommerceReadRegistry::fromArray($policy, $handlers);
            $registry = self::conversationRegistry($policy, $commerce);
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
        );
    }

    private static function conversationRegistry(
        AiToolPolicy $policy,
        AiCommerceReadRegistry $commerce,
    ): AiToolRegistry {
        $registrations = [];
        foreach (self::TOOLS as $tool) {
            $registrations[] = [
                'tool' => $tool,
                'descriptor' => $commerce->descriptor($tool),
                'handler' => static fn (array $inputs): array => $commerce->execute($tool, $inputs),
            ];
        }

        return AiToolRegistry::fromArray($policy, $registrations);
    }
}
