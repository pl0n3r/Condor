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
        self::assertSame([], $descriptor->outputNames());
        self::assertSame([], $descriptor->requiredOutputs());
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

    public function testDescriptorBindsCanonicalMinimizedOutputContract(): void
    {
        $policy = new AiToolPolicy();
        $descriptor = AiToolDescriptor::fromArray(
            $policy,
            [
                'tool' => 'catalog.read',
                'risk' => AiToolPolicy::READ_ONLY,
                'input_names' => ['category_ref'],
                'required_inputs' => [],
                'output_names' => ['label', 'product_ref', 'price_ref'],
                'required_outputs' => ['product_ref'],
            ],
        );

        self::assertSame(
            ['label', 'price_ref', 'product_ref'],
            $descriptor->outputNames(),
        );
        self::assertSame(['product_ref'], $descriptor->requiredOutputs());

        $descriptor->validateOutputs([
            'product_ref' => 'product:42',
            'label' => 'Industrial',
        ]);
        self::addToAssertionCount(1);
    }

    public function testInvalidOutputContractOrValueFailsClosed(): void
    {
        $policy = new AiToolPolicy();
        $base = [
            'tool' => 'catalog.read',
            'risk' => AiToolPolicy::READ_ONLY,
            'input_names' => [],
            'required_inputs' => [],
            'output_names' => ['label', 'product_ref'],
            'required_outputs' => ['product_ref'],
        ];

        $invalidDescriptors = [
            array_replace($base, ['output_names' => ['label', 'label']]),
            array_replace($base, ['output_names' => ['raw']]),
            array_replace($base, ['output_names' => ['Invalid-Key']]),
            array_replace($base, ['required_outputs' => ['missing_ref']]),
        ];

        foreach ($invalidDescriptors as $case) {
            try {
                AiToolDescriptor::fromArray($policy, $case);
                self::fail('El contrato de output inválido debía fallar cerrado.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }

        $missingPair = $base;
        unset($missingPair['required_outputs']);
        try {
            AiToolDescriptor::fromArray($policy, $missingPair);
            self::fail('Un output contract parcial debía fallar cerrado.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }

        $descriptor = AiToolDescriptor::fromArray($policy, $base);
        foreach (
            [
                [],
                ['product_ref' => 'product:42', 'unknown' => 'x'],
                ['product_ref' => 'product:42', 'raw' => 'opaque'],
            ] as $outputs
        ) {
            try {
                $descriptor->validateOutputs($outputs);
                self::fail('El output fuera del contrato debía fallar cerrado.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testOutputValuesAcceptOnlyScalarOrNullContract(): void
    {
        $policy = new AiToolPolicy();
        $descriptor = AiToolDescriptor::fromArray(
            $policy,
            [
                'tool' => 'catalog.read',
                'risk' => AiToolPolicy::READ_ONLY,
                'input_names' => [],
                'required_inputs' => [],
                'output_names' => [
                    'bool_value',
                    'float_value',
                    'int_value',
                    'null_value',
                    'string_value',
                ],
                'required_outputs' => ['string_value'],
            ],
        );

        $descriptor->validateOutputs([
            'string_value' => 'product:42',
            'int_value' => 42,
            'float_value' => 19.5,
            'bool_value' => true,
            'null_value' => null,
        ]);

        self::addToAssertionCount(1);
    }

    public function testNestedArrayObjectOrResourceOutputFailsClosed(): void
    {
        $policy = new AiToolPolicy();
        $descriptor = AiToolDescriptor::fromArray(
            $policy,
            [
                'tool' => 'catalog.read',
                'risk' => AiToolPolicy::READ_ONLY,
                'input_names' => [],
                'required_inputs' => [],
                'output_names' => ['value'],
                'required_outputs' => ['value'],
            ],
        );

        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        try {
            foreach (
                [
                    ['nested' => 'value'],
                    new \stdClass(),
                    $resource,
                    INF,
                ] as $value
            ) {
                try {
                    $descriptor->validateOutputs(['value' => $value]);
                    self::fail('El valor de output no escalar debía fallar cerrado.');
                } catch (DomainException) {
                    self::addToAssertionCount(1);
                }
            }
        } finally {
            fclose($resource);
        }
    }

    public function testLegacyDescriptorKeepsEmptyOutputContract(): void
    {
        $policy = new AiToolPolicy();
        $descriptor = AiToolDescriptor::fromArray(
            $policy,
            [
                'tool' => 'catalog.read',
                'risk' => AiToolPolicy::READ_ONLY,
                'input_names' => [],
                'required_inputs' => [],
            ],
        );

        $descriptor->validateOutputs([]);
        self::addToAssertionCount(1);

        $this->expectException(DomainException::class);
        $descriptor->validateOutputs(['value' => 'unexpected']);
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
