<?php

declare(strict_types=1);

namespace App\Tests\Domain\AI;

use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiToolDescriptorTest extends TestCase
{
    public function testDescriptorBindsCanonicalToolRiskAndMinimizedInputContract(): void
    {
        $policy = new AiToolPolicy();
        $descriptor = AiToolDescriptor::fromArray(
            $policy,
            [
                'tool' => 'content.draft.update',
                'risk' => AiToolPolicy::REVERSIBLE_WRITE,
                'input_names' => ['title', 'draft_id', 'content_ref'],
                'required_inputs' => ['draft_id'],
            ],
        );

        self::assertSame('content.draft.update', $descriptor->tool());
        self::assertSame(AiToolPolicy::REVERSIBLE_WRITE, $descriptor->risk());
        self::assertSame(
            ['content_ref', 'draft_id', 'title'],
            $descriptor->inputNames(),
        );
        self::assertSame(['draft_id'], $descriptor->requiredInputs());
        self::assertSame(
            [
                'tool_ref' => 'content.draft.update',
                'risk' => 'reversible_write',
                'input_names' => ['content_ref', 'draft_id', 'title'],
                'required_inputs' => ['draft_id'],
            ],
            $descriptor->snapshot(),
        );

        $descriptor->validateInputs([
            'draft_id' => 'draft:42',
            'content_ref' => 'content:7',
        ]);
        self::addToAssertionCount(1);
    }

    public function testUnknownRiskMismatchOrNoncanonicalDescriptorFailsClosed(): void
    {
        $policy = new AiToolPolicy();
        $base = [
            'tool' => 'catalog.read',
            'risk' => AiToolPolicy::READ_ONLY,
            'input_names' => ['category_ref', 'limit'],
            'required_inputs' => [],
        ];

        $cases = [
            array_replace($base, ['tool' => 'unknown.read']),
            array_replace($base, ['tool' => ' Catalog.Read ']),
            array_replace($base, ['risk' => AiToolPolicy::REVERSIBLE_WRITE]),
            array_replace($base, ['input_names' => ['limit', 'limit']]),
            array_replace($base, ['input_names' => ['Invalid-Key']]),
            array_replace($base, ['input_names' => ['payload']]),
            array_replace($base, ['required_inputs' => ['missing_ref']]),
            $base + ['handler' => 'arbitrary'],
        ];

        foreach ($cases as $case) {
            try {
                AiToolDescriptor::fromArray($policy, $case);
                self::fail('El descriptor inválido debía fallar cerrado.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }

        $descriptor = AiToolDescriptor::fromArray($policy, $base);
        foreach (
            [
                ['unknown' => 'x'],
                ['payload' => 'opaque'],
            ] as $inputs
        ) {
            try {
                $descriptor->validateInputs($inputs);
                self::fail('El input fuera del contrato debía fallar cerrado.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
