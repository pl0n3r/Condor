<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiDraftWriteRegistry;
use App\Domain\AI\AiToolPolicy;
use Closure;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiDraftWriteRegistryTest extends TestCase
{
    public function testRegistryBindsOnlyExistingDraftWriteToolsWithInjectedHandlers(): void
    {
        $policy = new AiToolPolicy();
        $before = $policy->allowlist();
        $registry = AiDraftWriteRegistry::fromArray($policy, self::validHandlers());

        self::assertSame(
            'content.draft.update',
            $registry->descriptor('content.draft.update')->tool(),
        );
        self::assertSame(
            'settings.draft.update',
            $registry->descriptor('settings.draft.update')->tool(),
        );
        self::assertSame(
            AiToolPolicy::REVERSIBLE_WRITE,
            $registry->descriptor('content.draft.update')->risk(),
        );
        self::assertSame(
            AiToolPolicy::REVERSIBLE_WRITE,
            $registry->descriptor('settings.draft.update')->risk(),
        );

        self::assertSame(
            [
                'draft_ref' => 'content_draft:homepage',
                'revision_ref' => 'revision:r43',
                'status' => 'updated',
            ],
            $registry->execute(
                'content.draft.update',
                [
                    'draft_ref' => 'content_draft:homepage',
                    'change_ref' => 'change:hero_title',
                    'expected_revision_ref' => 'revision:r42',
                ],
            ),
        );
        self::assertSame(
            [
                'draft_ref' => 'settings_draft:storefront',
                'revision_ref' => 'revision:r10',
                'status' => 'conflict',
            ],
            $registry->execute(
                'settings.draft.update',
                [
                    'draft_ref' => 'settings_draft:storefront',
                    'change_ref' => 'change:currency',
                    'expected_revision_ref' => 'revision:r9',
                ],
            ),
        );
        self::assertSame($before, $policy->allowlist());
    }

    public function testInvalidInputsFailBeforeHandlerAndInvalidOutputsFailClosed(): void
    {
        $calls = 0;
        $registry = AiDraftWriteRegistry::fromArray(
            new AiToolPolicy(),
            [
                'content.draft.update' => static function (array $inputs) use (&$calls): array {
                    ++$calls;

                    return [
                        'draft_ref' => 'content_draft:other',
                        'revision_ref' => 'revision:r43',
                        'status' => 'updated',
                    ];
                },
                'settings.draft.update' => static fn (array $inputs): array => [
                    'draft_ref' => $inputs['draft_ref'],
                    'revision_ref' => 'revision:r10',
                    'status' => 'conflict',
                ],
            ],
        );

        $this->assertDomainFailure(
            static fn (): array => $registry->execute(
                'content.draft.update',
                [
                    'draft_ref' => 'content draft homepage',
                    'change_ref' => 'change:hero_title',
                ],
            ),
        );
        self::assertSame(0, $calls);

        $this->assertDomainFailure(
            static fn (): array => $registry->execute(
                'content.draft.update',
                [
                    'draft_ref' => 'content_draft:homepage',
                    'change_ref' => 'change:hero_title',
                    'expected_revision_ref' => 'revision:r42',
                ],
            ),
        );
        self::assertSame(1, $calls);

        $this->assertDomainFailure(
            static fn (): array => $registry->execute(
                'settings.draft.update',
                [
                    'draft_ref' => 'settings_draft:storefront',
                    'change_ref' => 'change:currency',
                ],
            ),
        );
    }

    public function testMissingExtraOrNonClosureHandlersFailClosed(): void
    {
        $policy = new AiToolPolicy();
        $valid = static fn (array $inputs): array => [
            'draft_ref' => $inputs['draft_ref'],
            'revision_ref' => 'revision:r2',
            'status' => 'updated',
        ];
        $cases = [
            ['content.draft.update' => $valid],
            [
                'content.draft.update' => $valid,
                'settings.draft.update' => $valid,
                'knowledge.read' => $valid,
            ],
            [
                'content.draft.update' => 'strlen',
                'settings.draft.update' => $valid,
            ],
        ];

        foreach ($cases as $handlers) {
            $this->assertDomainFailure(
                static fn (): AiDraftWriteRegistry => AiDraftWriteRegistry::fromArray(
                    $policy,
                    $handlers,
                ),
            );
        }
    }

    public function testRegistryPreservesReversibleWriteRiskAndRejectsUnknownTools(): void
    {
        $policy = new AiToolPolicy();
        $before = $policy->allowlist();
        $registry = AiDraftWriteRegistry::fromArray($policy, self::validHandlers());

        self::assertSame(
            AiToolPolicy::REVERSIBLE_WRITE,
            $registry->descriptor('content.draft.update')->risk(),
        );
        self::assertSame(
            AiToolPolicy::REVERSIBLE_WRITE,
            $registry->descriptor('settings.draft.update')->risk(),
        );
        self::assertSame($before, $policy->allowlist());

        foreach (['catalog.read', 'Content.Draft.Update', 'unknown.draft.update'] as $tool) {
            $this->assertDomainFailure(
                static fn (): object => $registry->descriptor($tool),
            );
        }
    }

    public function testBindingIsDeterministicAndCarriesNoFreeformPayload(): void
    {
        $policy = new AiToolPolicy();
        $handlers = self::validHandlers();
        $first = AiDraftWriteRegistry::fromArray($policy, $handlers);
        $second = AiDraftWriteRegistry::fromArray($policy, $handlers);

        self::assertSame(
            $first->descriptor('content.draft.update')->snapshot(),
            $second->descriptor('content.draft.update')->snapshot(),
        );
        self::assertSame(
            $first->descriptor('settings.draft.update')->snapshot(),
            $second->descriptor('settings.draft.update')->snapshot(),
        );

        $this->assertDomainFailure(
            static fn (): array => $first->execute(
                'content.draft.update',
                [
                    'draft_ref' => 'content_draft:homepage',
                    'change_ref' => 'change:hero_title',
                    'text' => 'contenido libre',
                ],
            ),
        );
    }

    /** @return array<string, Closure> */
    private static function validHandlers(): array
    {
        return [
            'content.draft.update' => static fn (array $inputs): array => [
                'draft_ref' => $inputs['draft_ref'],
                'revision_ref' => 'revision:r43',
                'status' => 'updated',
            ],
            'settings.draft.update' => static fn (array $inputs): array => [
                'draft_ref' => $inputs['draft_ref'],
                'revision_ref' => 'revision:r10',
                'status' => 'conflict',
            ],
        ];
    }

    private function assertDomainFailure(Closure $operation): void
    {
        try {
            $operation();
            self::fail('La operación de draft inválida debía fallar cerrado.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
    }
}
