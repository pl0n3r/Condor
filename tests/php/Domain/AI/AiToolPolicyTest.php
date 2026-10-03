<?php

declare(strict_types=1);

namespace App\Tests\Domain\AI;

use App\Domain\AI\AiToolPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiToolPolicyTest extends TestCase
{
    public function testExplicitAllowlistClassifiesReadOnlyAndReversibleTools(): void
    {
        $policy = new AiToolPolicy();

        foreach (['catalog.read', 'inventory.read', 'knowledge.read'] as $tool) {
            self::assertSame(AiToolPolicy::READ_ONLY, $policy->risk($tool));
            self::assertTrue($policy->allowsAutonomousExecution($tool));
        }

        foreach (['content.draft.update', 'settings.draft.update'] as $tool) {
            self::assertSame(AiToolPolicy::REVERSIBLE_WRITE, $policy->risk($tool));
            self::assertTrue($policy->allowsAutonomousExecution($tool));
        }

        self::assertSame(
            AiToolPolicy::SENSITIVE,
            $policy->risk('identity.permission.change'),
        );

        $serialized = implode(' ', array_keys($policy->allowlist()));
        foreach (
            [
                'sql',
                'db',
                'database',
                'provider',
                'model',
                'channel',
                'secret',
                'token',
                'credential',
            ] as $forbidden
        ) {
            self::assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function testUnknownSensitiveOrDirectDatabaseToolsFailClosed(): void
    {
        $policy = new AiToolPolicy();

        self::assertFalse(
            $policy->allowsAutonomousExecution('identity.permission.change'),
        );

        foreach (
            [
                '',
                'unknown.tool',
                'sql.query',
                'db.query',
                'database.read',
                'provider.openai',
                'model.gpt',
                'channel.whatsapp',
                'secret.read',
                'token.read',
            ] as $tool
        ) {
            self::assertFalse(
                $policy->allowsAutonomousExecution($tool),
                sprintf('La tool %s debía fallar cerrado.', $tool),
            );
        }

        foreach (
            [
                '',
                'unknown.tool',
                'sql.query',
                'database.read',
                'provider.openai',
                'channel.whatsapp',
                'secret.read',
            ] as $tool
        ) {
            $this->assertRiskRejected($policy, $tool);
        }
    }

    private function assertRiskRejected(AiToolPolicy $policy, string $tool): void
    {
        try {
            $policy->risk($tool);
            self::fail('La clasificación inválida debía fallar.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
    }
}
