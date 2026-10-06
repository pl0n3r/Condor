<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiDraftWriteRuntime;
use App\Application\AI\AiToolReplayGuard;
use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AiDraftWriteRuntimeTest extends TestCase
{
    public function testDraftUpdatesFlowThroughExistingConversationCoreWithReversibleWriteReceipt(): void
    {
        $policy = new AiToolPolicy();
        $calls = 0;
        $handlers = self::countedHandlers($calls);
        $guard = new AiToolReplayGuard();
        $at = new DateTimeImmutable('2026-10-06T00:00:00Z');

        foreach ([
            'content.draft.update' => [
                'inputs' => [
                    'draft_ref' => 'content_draft:homepage',
                    'change_ref' => 'change:hero_title',
                    'expected_revision_ref' => 'revision:r42',
                ],
                'result' => [
                    'draft_ref' => 'content_draft:homepage',
                    'revision_ref' => 'revision:r43',
                    'status' => 'updated',
                ],
            ],
            'settings.draft.update' => [
                'inputs' => [
                    'draft_ref' => 'settings_draft:storefront',
                    'change_ref' => 'change:currency',
                    'expected_revision_ref' => 'revision:r9',
                ],
                'result' => [
                    'draft_ref' => 'settings_draft:storefront',
                    'revision_ref' => 'revision:r10',
                    'status' => 'updated',
                ],
            ],
        ] as $tool => $case) {
            $result = AiDraftWriteRuntime::turn(
                self::context($policy, $tool),
                $policy,
                self::turn($tool, $case['inputs'], 'request:' . str_replace('.', '-', $tool)),
                $at,
                $handlers,
                $guard,
            );

            self::assertSame('completed', $result['status']);
            self::assertSame('tool', $result['route']);
            self::assertTrue($result['executed']);
            self::assertSame($case['result'], $result['tool_result']);
            self::assertSame('authorized', $result['receipt']['decision']);
            self::assertSame(AiToolPolicy::REVERSIBLE_WRITE, $result['receipt']['risk']);
            self::assertSame('success', $result['receipt']['outcome']);
            self::assertSame('tenant:tenant-a', $result['receipt']['tenant_ref']);
            self::assertSame($tool, $result['receipt']['tool_ref']);
            self::assertArrayNotHasKey('inputs', $result['receipt']);
            self::assertArrayNotHasKey('tool_result', $result['receipt']);
        }

        self::assertSame(2, $calls);
    }

    public function testMissingReplayGuardHandoffsBeforeHandlerExecution(): void
    {
        $policy = new AiToolPolicy();
        $calls = 0;
        $result = AiDraftWriteRuntime::turn(
            self::context($policy, 'content.draft.update'),
            $policy,
            self::turn('content.draft.update', self::contentInputs()),
            new DateTimeImmutable('2026-10-06T00:00:00Z'),
            self::countedHandlers($calls),
        );

        self::assertSame('handoff', $result['status']);
        self::assertSame('tool_replay_guard_required', $result['reason']);
        self::assertFalse($result['executed']);
        self::assertSame(0, $calls);
        self::assertArrayNotHasKey('receipt', $result);
    }

    public function testDuplicateReplayKeyNeverExecutesHandlerTwice(): void
    {
        $policy = new AiToolPolicy();
        $calls = 0;
        $guard = new AiToolReplayGuard();
        $handlers = self::countedHandlers($calls);
        $context = self::context($policy, 'content.draft.update');
        $turn = self::turn('content.draft.update', self::contentInputs());
        $at = new DateTimeImmutable('2026-10-06T00:00:00Z');

        $first = AiDraftWriteRuntime::turn($context, $policy, $turn, $at, $handlers, $guard);
        $duplicate = AiDraftWriteRuntime::turn($context, $policy, $turn, $at, $handlers, $guard);

        self::assertSame('completed', $first['status']);
        self::assertSame('handoff', $duplicate['status']);
        self::assertSame('tool_replay_detected', $duplicate['reason']);
        self::assertFalse($duplicate['executed']);
        self::assertSame(1, $calls);
        self::assertArrayNotHasKey('receipt', $duplicate);

        $serialized = json_encode([$first, $duplicate], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('replay:', $serialized);
        self::assertStringNotContainsString('claims', $serialized);
    }

    public function testCrossTenantUnknownToolInvalidContractOrHandlerFailureFailClosed(): void
    {
        $policy = new AiToolPolicy();
        $calls = 0;
        $handlers = self::countedHandlers($calls);
        $at = new DateTimeImmutable('2026-10-06T00:00:00Z');

        $crossTenant = AiDraftWriteRuntime::turn(
            self::context($policy, 'content.draft.update'),
            $policy,
            array_replace(
                self::turn('content.draft.update', self::contentInputs(), 'request:cross-tenant'),
                ['tenant_id' => 'tenant-b'],
            ),
            $at,
            $handlers,
            new AiToolReplayGuard(),
        );
        self::assertSame('handoff', $crossTenant['status']);
        self::assertSame('tenant_context_mismatch', $crossTenant['reason']);
        self::assertSame(0, $calls);

        $unknown = AiDraftWriteRuntime::turn(
            self::context($policy, 'content.draft.update'),
            $policy,
            self::turn('unknown.draft.update', [], 'request:unknown-tool'),
            $at,
            $handlers,
            new AiToolReplayGuard(),
        );
        self::assertSame('denied', $unknown['status']);
        self::assertSame('tool_request_denied', $unknown['reason']);
        self::assertSame(0, $calls);

        $invalidInput = AiDraftWriteRuntime::turn(
            self::context($policy, 'content.draft.update'),
            $policy,
            self::turn(
                'content.draft.update',
                ['draft_ref' => 'content draft homepage', 'change_ref' => 'change:hero_title'],
                'request:invalid-input',
            ),
            $at,
            $handlers,
            new AiToolReplayGuard(),
        );
        self::assertSame('handoff', $invalidInput['status']);
        self::assertSame('tool_failed', $invalidInput['reason']);
        self::assertTrue($invalidInput['executed']);
        self::assertSame('failure', $invalidInput['receipt']['outcome']);
        self::assertSame(0, $calls);

        $missingHandler = AiDraftWriteRuntime::turn(
            self::context($policy, 'content.draft.update'),
            $policy,
            self::turn('content.draft.update', self::contentInputs(), 'request:missing-handler'),
            $at,
            ['content.draft.update' => $handlers['content.draft.update']],
            new AiToolReplayGuard(),
        );
        self::assertSame('handoff', $missingHandler['status']);
        self::assertSame('tool_handler_unavailable', $missingHandler['reason']);
        self::assertSame(0, $calls);

        $handlerFailure = AiDraftWriteRuntime::turn(
            self::context($policy, 'content.draft.update'),
            $policy,
            self::turn('content.draft.update', self::contentInputs(), 'request:handler-failure'),
            $at,
            [
                'content.draft.update' => static function (array $inputs) use (&$calls): array {
                    ++$calls;
                    throw new \DomainException('fail closed');
                },
                'settings.draft.update' => $handlers['settings.draft.update'],
            ],
            new AiToolReplayGuard(),
        );
        self::assertSame('handoff', $handlerFailure['status']);
        self::assertSame('tool_failed', $handlerFailure['reason']);
        self::assertTrue($handlerFailure['executed']);
        self::assertSame('failure', $handlerFailure['receipt']['outcome']);
        self::assertSame(1, $calls);

        $invalidOutput = AiDraftWriteRuntime::turn(
            self::context($policy, 'content.draft.update'),
            $policy,
            self::turn('content.draft.update', self::contentInputs(), 'request:invalid-output'),
            $at,
            [
                'content.draft.update' => static function (array $inputs) use (&$calls): array {
                    ++$calls;
                    return [
                        'draft_ref' => 'content_draft:other',
                        'revision_ref' => 'revision:r43',
                        'status' => 'updated',
                    ];
                },
                'settings.draft.update' => $handlers['settings.draft.update'],
            ],
            new AiToolReplayGuard(),
        );
        self::assertSame('handoff', $invalidOutput['status']);
        self::assertSame('tool_failed', $invalidOutput['reason']);
        self::assertTrue($invalidOutput['executed']);
        self::assertSame('failure', $invalidOutput['receipt']['outcome']);
        self::assertSame(2, $calls);
    }

    public function testRuntimeHasNoDatabaseNetworkProviderRealDataOrAuthorityExpansion(): void
    {
        $policy = new AiToolPolicy();
        $before = $policy->allowlist();
        $calls = 0;
        $result = AiDraftWriteRuntime::turn(
            self::context($policy, 'settings.draft.update'),
            $policy,
            self::turn(
                'settings.draft.update',
                [
                    'draft_ref' => 'settings_draft:storefront',
                    'change_ref' => 'change:currency',
                    'expected_revision_ref' => 'revision:r9',
                ],
                'request:authority-boundary',
            ),
            new DateTimeImmutable('2026-10-06T00:00:00Z'),
            self::countedHandlers($calls),
            new AiToolReplayGuard(),
        );

        self::assertSame('completed', $result['status']);
        self::assertSame(AiToolPolicy::REVERSIBLE_WRITE, $result['receipt']['risk']);
        self::assertSame($before, $policy->allowlist());
        self::assertSame(1, $calls);

        $serialized = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['password', 'secret', 'token', 'credential', 'provider', 'model', 'channel'] as $marker) {
            self::assertStringNotContainsString($marker, strtolower($serialized));
        }
    }

    private static function context(AiToolPolicy $policy, string $tool): AiTenantContext
    {
        return AiTenantContext::fromArray([
            'tenant_id' => 'tenant-a',
            'tool' => $tool,
            'knowledge_refs' => [],
        ], $policy);
    }

    /** @param array<string, mixed> $inputs @return array<string, mixed> */
    private static function turn(
        string $tool,
        array $inputs,
        string $requestRef = 'request:draft-runtime',
    ): array {
        return [
            'intent' => 'tool',
            'tenant_id' => 'tenant-a',
            'tool' => $tool,
            'inputs' => $inputs,
            'request_ref' => $requestRef,
            'evidence_ref' => 'evidence:draft-runtime',
            'timestamp' => '2026-10-06T00:00:00+00:00',
        ];
    }

    /** @return array<string, string> */
    private static function contentInputs(): array
    {
        return [
            'draft_ref' => 'content_draft:homepage',
            'change_ref' => 'change:hero_title',
            'expected_revision_ref' => 'revision:r42',
        ];
    }

    /** @return array<string, \Closure> */
    private static function countedHandlers(int &$calls): array
    {
        return [
            'content.draft.update' => static function (array $inputs) use (&$calls): array {
                ++$calls;
                return [
                    'draft_ref' => $inputs['draft_ref'],
                    'revision_ref' => 'revision:r43',
                    'status' => 'updated',
                ];
            },
            'settings.draft.update' => static function (array $inputs) use (&$calls): array {
                ++$calls;
                return [
                    'draft_ref' => $inputs['draft_ref'],
                    'revision_ref' => 'revision:r10',
                    'status' => 'updated',
                ];
            },
        ];
    }
}
