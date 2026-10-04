<?php

declare(strict_types=1);

namespace App\Domain\AI;

use DomainException;

final readonly class AiToolDescriptor
{
    private const MAX_INPUT_NAMES = 32;

    private const MAX_OUTPUT_NAMES = 32;

    private const FORBIDDEN_INPUT_SEGMENTS = [
        'payload',
        'raw',
        'prompt',
        'transcript',
        'secret',
        'token',
        'credential',
        'credentials',
        'password',
        'authorization',
    ];

    private const FORBIDDEN_OUTPUT_SEGMENTS = self::FORBIDDEN_INPUT_SEGMENTS;

    /** @var AiToolPolicy::READ_ONLY|AiToolPolicy::REVERSIBLE_WRITE|AiToolPolicy::SENSITIVE */
    private string $risk;

    /** @var list<string> */
    private array $inputNames;

    /** @var list<string> */
    private array $requiredInputs;

    /** @var list<string> */
    private array $outputNames;

    /** @var list<string> */
    private array $requiredOutputs;

    /**
     * @param AiToolPolicy::READ_ONLY|AiToolPolicy::REVERSIBLE_WRITE|AiToolPolicy::SENSITIVE $risk
     * @param list<string> $inputNames
     * @param list<string> $requiredInputs
     * @param list<string> $outputNames
     * @param list<string> $requiredOutputs
     */
    private function __construct(
        private string $tool,
        string $risk,
        array $inputNames,
        array $requiredInputs,
        array $outputNames,
        array $requiredOutputs,
    ) {
        $this->risk = $risk;
        $this->inputNames = $inputNames;
        $this->requiredInputs = $requiredInputs;
        $this->outputNames = $outputNames;
        $this->requiredOutputs = $requiredOutputs;
    }

    /** @param array<string, mixed> $descriptor */
    public static function fromArray(
        AiToolPolicy $policy,
        array $descriptor,
    ): self {
        $keys = array_keys($descriptor);
        sort($keys);
        $legacyKeys = ['input_names', 'required_inputs', 'risk', 'tool'];
        $outputKeys = [
            'input_names',
            'output_names',
            'required_inputs',
            'required_outputs',
            'risk',
            'tool',
        ];
        if ($keys !== $legacyKeys && $keys !== $outputKeys) {
            throw new DomainException('Descriptor de tool IA no canónico.');
        }

        $tool = $descriptor['tool'];
        $risk = $descriptor['risk'];
        $inputNames = $descriptor['input_names'];
        $requiredInputs = $descriptor['required_inputs'];
        $outputNames = $descriptor['output_names'] ?? [];
        $requiredOutputs = $descriptor['required_outputs'] ?? [];

        if (
            !is_string($tool)
            || !is_string($risk)
            || !is_array($inputNames)
            || !is_array($requiredInputs)
            || !is_array($outputNames)
            || !is_array($requiredOutputs)
        ) {
            throw new DomainException('Descriptor de tool IA inválido.');
        }

        $canonicalTool = strtolower(trim($tool));
        if ($tool !== $canonicalTool) {
            throw new DomainException('Tool de descriptor IA no canónica.');
        }

        $canonicalRisk = $policy->risk($canonicalTool);
        if ($risk !== $canonicalRisk) {
            throw new DomainException('Riesgo de descriptor IA inconsistente con policy.');
        }

        $inputs = self::normalizeInputNames($inputNames, 'Input');
        $required = self::normalizeInputNames($requiredInputs, 'Input requerido');

        foreach ($required as $name) {
            if (!in_array($name, $inputs, true)) {
                throw new DomainException('Input requerido fuera de la allowlist del descriptor.');
            }
        }

        $outputs = self::normalizeOutputNames($outputNames, 'Output');
        $requiredOutputNames = self::normalizeOutputNames(
            $requiredOutputs,
            'Output requerido',
        );

        foreach ($requiredOutputNames as $name) {
            if (!in_array($name, $outputs, true)) {
                throw new DomainException('Output requerido fuera de la allowlist del descriptor.');
            }
        }

        return new self(
            $canonicalTool,
            $canonicalRisk,
            $inputs,
            $required,
            $outputs,
            $requiredOutputNames,
        );
    }

    public function tool(): string
    {
        return $this->tool;
    }

    /** @return AiToolPolicy::READ_ONLY|AiToolPolicy::REVERSIBLE_WRITE|AiToolPolicy::SENSITIVE */
    public function risk(): string
    {
        return $this->risk;
    }

    /** @return list<string> */
    public function inputNames(): array
    {
        return $this->inputNames;
    }

    /** @return list<string> */
    public function requiredInputs(): array
    {
        return $this->requiredInputs;
    }

    /** @return list<string> */
    public function outputNames(): array
    {
        return $this->outputNames;
    }

    /** @return list<string> */
    public function requiredOutputs(): array
    {
        return $this->requiredOutputs;
    }

    /**
     * @param array<string, mixed> $inputs
     */
    public function validateInputs(array $inputs): void
    {
        if (count($inputs) > self::MAX_INPUT_NAMES) {
            throw new DomainException('Inputs de tool IA exceden el contrato.');
        }

        foreach (array_keys($inputs) as $name) {
            self::validateInputName($name);
            if (!in_array($name, $this->inputNames, true)) {
                throw new DomainException('Input fuera del contrato de la tool IA.');
            }
        }

        foreach ($this->requiredInputs as $required) {
            if (!array_key_exists($required, $inputs)) {
                throw new DomainException('Falta input requerido por la tool IA.');
            }
        }
    }

    /**
     * @param array<string, mixed> $outputs
     */
    public function validateOutputs(array $outputs): void
    {
        if (count($outputs) > self::MAX_OUTPUT_NAMES) {
            throw new DomainException('Outputs de tool IA exceden el contrato.');
        }

        foreach (array_keys($outputs) as $name) {
            self::validateOutputName($name);
            if (!in_array($name, $this->outputNames, true)) {
                throw new DomainException('Output fuera del contrato de la tool IA.');
            }
        }

        foreach ($this->requiredOutputs as $required) {
            if (!array_key_exists($required, $outputs)) {
                throw new DomainException('Falta output requerido por la tool IA.');
            }
        }
    }

    /**
     * @return array{
     *     tool_ref:string,
     *     risk:'read_only'|'reversible_write'|'sensitive',
     *     input_names:list<string>,
     *     required_inputs:list<string>
     * }
     */
    public function snapshot(): array
    {
        return [
            'tool_ref' => $this->tool,
            'risk' => $this->risk,
            'input_names' => $this->inputNames,
            'required_inputs' => $this->requiredInputs,
        ];
    }

    /**
     * @param array<mixed> $names
     * @return list<string>
     */
    private static function normalizeInputNames(array $names, string $noun): array
    {
        if (!array_is_list($names) || count($names) > self::MAX_INPUT_NAMES) {
            throw new DomainException($noun . ' de descriptor IA inválido.');
        }

        $normalized = [];
        foreach ($names as $name) {
            if (!is_string($name)) {
                throw new DomainException($noun . ' de descriptor IA inválido.');
            }

            self::validateInputName($name);
            if (in_array($name, $normalized, true)) {
                throw new DomainException($noun . ' duplicado en descriptor IA.');
            }

            $normalized[] = $name;
        }

        sort($normalized);

        return $normalized;
    }

    /**
     * @param array<mixed> $names
     * @return list<string>
     */
    private static function normalizeOutputNames(array $names, string $noun): array
    {
        if (!array_is_list($names) || count($names) > self::MAX_OUTPUT_NAMES) {
            throw new DomainException($noun . ' de descriptor IA inválido.');
        }

        $normalized = [];
        foreach ($names as $name) {
            if (!is_string($name)) {
                throw new DomainException($noun . ' de descriptor IA inválido.');
            }

            self::validateOutputName($name);
            if (in_array($name, $normalized, true)) {
                throw new DomainException($noun . ' duplicado en descriptor IA.');
            }

            $normalized[] = $name;
        }

        sort($normalized);

        return $normalized;
    }

    private static function validateInputName(string $name): void
    {
        if (
            strlen($name) > 64
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) !== 1
        ) {
            throw new DomainException('Nombre de input IA no canónico.');
        }

        $segments = explode('_', $name);
        foreach ($segments as $segment) {
            if (in_array($segment, self::FORBIDDEN_INPUT_SEGMENTS, true)) {
                throw new DomainException('Input fuera de la frontera minimizada de IA.');
            }
        }
    }

    private static function validateOutputName(string $name): void
    {
        if (
            strlen($name) > 64
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) !== 1
        ) {
            throw new DomainException('Nombre de output IA no canónico.');
        }

        $segments = explode('_', $name);
        foreach ($segments as $segment) {
            if (in_array($segment, self::FORBIDDEN_OUTPUT_SEGMENTS, true)) {
                throw new DomainException('Output fuera de la frontera minimizada de IA.');
            }
        }
    }
}
