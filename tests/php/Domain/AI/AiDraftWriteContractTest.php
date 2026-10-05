<?php

declare(strict_types=1);

namespace App\Tests\Domain\AI;

use App\Domain\AI\AiDraftWriteContract;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiDraftWriteContractTest extends TestCase
{
    public function testDescriptorsReuseExistingReversibleWritePolicy(): void
    {
        $policy = new AiToolPolicy();
        $before = $policy->allowlist();
        $contract = new AiDraftWriteContract($policy);

        self::assertSame('content.draft.update', $contract->contentDescriptor()->tool());
        self::assertSame('settings.draft.update', $contract->settingsDescriptor()->tool());
        self::assertSame(AiToolPolicy::REVERSIBLE_WRITE, $contract->contentDescriptor()->risk());
        self::assertSame(AiToolPolicy::REVERSIBLE_WRITE, $contract->settingsDescriptor()->risk());
        self::assertSame(
            ['draft_ref', 'revision_ref', 'status'],
            $contract->contentDescriptor()->outputNames(),
        );
        self::assertSame(
            ['draft_ref', 'revision_ref', 'status'],
            $contract->contentDescriptor()->requiredOutputs(),
        );
        self::assertSame(
            ['draft_ref', 'revision_ref', 'status'],
            $contract->settingsDescriptor()->outputNames(),
        );
        self::assertSame(
            ['draft_ref', 'revision_ref', 'status'],
            $contract->settingsDescriptor()->requiredOutputs(),
        );
        self::assertSame($before, $policy->allowlist());
    }

    public function testCanonicalOpaqueDraftShapes(): void
    {
        $contract = new AiDraftWriteContract(new AiToolPolicy());

        $contract->validateContentInputs([
            'draft_ref' => 'content_draft:homepage',
            'change_ref' => 'change:hero_title',
            'expected_revision_ref' => 'revision:r42',
        ]);
        $contract->validateContentOutputs([
            'draft_ref' => 'content_draft:homepage',
            'revision_ref' => 'revision:r43',
            'status' => 'updated',
        ]);
        $contract->validateSettingsInputs([
            'draft_ref' => 'settings_draft:storefront',
            'change_ref' => 'change:currency',
        ]);
        $contract->validateSettingsOutputs([
            'draft_ref' => 'settings_draft:storefront',
            'revision_ref' => 'revision:r10',
            'status' => 'conflict',
        ]);

        self::addToAssertionCount(4);
    }

    public function testInvalidDraftShapesFailClosed(): void
    {
        $contract = new AiDraftWriteContract(new AiToolPolicy());
        $cases = [
            fn () => $contract->validateContentInputs([
                'draft_ref' => 'settings_draft:storefront',
                'change_ref' => 'change:hero_title',
            ]),
            fn () => $contract->validateContentInputs([
                'draft_ref' => 'content_draft:homepage',
                'change_ref' => 'hero title libre',
            ]),
            fn () => $contract->validateContentInputs([
                'draft_ref' => 'content_draft:homepage',
                'change_ref' => 'change:hero_title',
                'text' => 'contenido libre',
            ]),
            fn () => $contract->validateSettingsInputs([
                'draft_ref' => 'settings_draft:storefront',
                'change_ref' => 'change:currency',
                'expected_revision_ref' => 'r10',
            ]),
            fn () => $contract->validateContentOutputs([
                'draft_ref' => 'content_draft:homepage',
                'revision_ref' => 'revision:r43',
                'status' => 'updated',
                'applied' => true,
            ]),
            fn () => $contract->validateSettingsOutputs([
                'draft_ref' => 'settings_draft:storefront',
                'revision_ref' => 'settings_draft:storefront',
                'status' => 'conflict',
            ]),
            fn () => $contract->validateSettingsOutputs([
                'draft_ref' => 'settings_draft:storefront',
                'revision_ref' => 'revision:r10',
                'status' => 'pending',
            ]),
        ];

        foreach ($cases as $case) {
            try {
                $case();
                self::fail('El caso inválido debía fallar cerrado.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
