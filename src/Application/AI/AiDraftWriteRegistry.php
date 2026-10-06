<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Domain\AI\AiDraftWriteContract;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;
use Closure;
use DomainException;

final readonly class AiDraftWriteRegistry
{
    private const TOOLS = ['content.draft.update', 'settings.draft.update'];

    private function __construct(private AiToolRegistry $registry)
    {
    }

    /**
     * @param array<string, mixed> $handlers
     */
    public static function fromArray(AiToolPolicy $policy, array $handlers): self
    {
        $keys = array_keys($handlers);
        sort($keys);

        if ($keys !== self::TOOLS) {
            throw new DomainException('Handlers de draft incompletos o no canónicos.');
        }

        $contract = new AiDraftWriteContract($policy);
        $registrations = [];

        foreach (self::TOOLS as $tool) {
            $handler = $handlers[$tool] ?? null;
            if (!$handler instanceof Closure) {
                throw new DomainException('Handler de draft debe ser Closure.');
            }

            $descriptor = $tool === 'content.draft.update'
                ? $contract->contentDescriptor()
                : $contract->settingsDescriptor();

            $registrations[] = [
                'tool' => $tool,
                'descriptor' => $descriptor,
                'handler' => self::validatedHandler($contract, $tool, $handler),
            ];
        }

        return new self(AiToolRegistry::fromArray($policy, $registrations));
    }

    /**
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    public function execute(string $tool, array $inputs): array
    {
        $binding = $this->registry->resolveWithInputs($tool, $inputs);
        $handler = $binding['handler'];
        $outputs = $handler($binding['inputs']);

        if (!is_array($outputs)) {
            throw new DomainException('Handler de draft devolvió output inválido.');
        }

        return $outputs;
    }

    public function descriptor(string $tool): AiToolDescriptor
    {
        return $this->registry->resolve($tool)['descriptor'];
    }

    private static function validatedHandler(
        AiDraftWriteContract $contract,
        string $tool,
        Closure $handler,
    ): Closure {
        [$validateInputs, $validateOutputs] = match ($tool) {
            'content.draft.update' => [
                Closure::fromCallable([$contract, 'validateContentInputs']),
                Closure::fromCallable([$contract, 'validateContentOutputs']),
            ],
            'settings.draft.update' => [
                Closure::fromCallable([$contract, 'validateSettingsInputs']),
                Closure::fromCallable([$contract, 'validateSettingsOutputs']),
            ],
            default => throw new DomainException('Tool de draft no soportada.'),
        };

        return static function (array $inputs) use (
            $handler,
            $validateInputs,
            $validateOutputs,
        ): array {
            $validateInputs($inputs);
            $outputs = $handler($inputs);
            if (!is_array($outputs)) {
                throw new DomainException('Handler de draft devolvió output inválido.');
            }
            $validateOutputs($outputs);
            self::validateResultCoherence($inputs, $outputs);

            return $outputs;
        };
    }

    /**
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $outputs
     */
    private static function validateResultCoherence(array $inputs, array $outputs): void
    {
        if ($outputs['draft_ref'] !== $inputs['draft_ref']) {
            throw new DomainException('draft_ref de salida no corresponde al draft solicitado.');
        }

        $expectedRevision = $inputs['expected_revision_ref'] ?? null;
        if ($outputs['status'] === 'conflict' && $expectedRevision === null) {
            throw new DomainException('conflict requiere expected_revision_ref.');
        }

        if ($expectedRevision !== null && $outputs['revision_ref'] === $expectedRevision) {
            throw new DomainException('revision_ref no puede repetir la revisión esperada.');
        }
    }
}
