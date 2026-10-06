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

    /** @param array<string, mixed> $handlers */
    public static function fromArray(AiToolPolicy $policy, array $handlers): self
    {
        $keys = array_keys($handlers);
        sort($keys);
        if ($keys !== self::TOOLS) {
            throw new DomainException('Handlers de draft incompletos o no canónicos.');
        }

        $contract = new AiDraftWriteContract($policy);
        $definitions = [
            'content.draft.update' => [
                $contract->contentDescriptor(),
                Closure::fromCallable([$contract, 'validateContentInputs']),
                Closure::fromCallable([$contract, 'validateContentOutputs']),
            ],
            'settings.draft.update' => [
                $contract->settingsDescriptor(),
                Closure::fromCallable([$contract, 'validateSettingsInputs']),
                Closure::fromCallable([$contract, 'validateSettingsOutputs']),
            ],
        ];

        $registrations = [];
        foreach ($definitions as $tool => [$descriptor, $inputGuard, $outputGuard]) {
            $registrations[] = [
                'tool' => $tool,
                'descriptor' => $descriptor,
                'handler' => self::guarded(
                    self::handler($handlers, $tool),
                    $inputGuard,
                    $outputGuard,
                ),
            ];
        }

        return new self(AiToolRegistry::fromArray($policy, $registrations));
    }

    /** @param array<string, mixed> $inputs
     *  @return array<string, mixed>
     */
    public function execute(string $tool, array $inputs): array
    {
        $binding = $this->registry->resolveWithInputs($tool, $inputs);
        $handler = $binding['handler'];

        return $handler($binding['inputs']);
    }

    public function descriptor(string $tool): AiToolDescriptor
    {
        return $this->registry->resolve($tool)['descriptor'];
    }

    /** @param array<string, mixed> $handlers */
    private static function handler(array $handlers, string $tool): Closure
    {
        $handler = $handlers[$tool] ?? null;
        if (!$handler instanceof Closure) {
            throw new DomainException('Handler de draft debe ser Closure.');
        }

        return $handler;
    }

    private static function guarded(
        Closure $handler,
        Closure $inputGuard,
        Closure $outputGuard,
    ): Closure {
        return static function (array $inputs) use ($handler, $inputGuard, $outputGuard): array {
            $inputGuard($inputs);
            $outputs = $handler($inputs);
            if (!is_array($outputs)) {
                throw new DomainException('Handler de draft devolvió output inválido.');
            }
            $outputGuard($outputs);
            self::assertCoherent($inputs, $outputs);

            return $outputs;
        };
    }

    /** @param array<string, mixed> $inputs
     *  @param array<string, mixed> $outputs
     */
    private static function assertCoherent(array $inputs, array $outputs): void
    {
        if ($outputs['draft_ref'] !== $inputs['draft_ref']) {
            throw new DomainException('draft_ref de salida no corresponde al draft solicitado.');
        }

        $expected = $inputs['expected_revision_ref'] ?? null;
        if ($outputs['status'] === 'conflict' && $expected === null) {
            throw new DomainException('conflict requiere expected_revision_ref.');
        }
        if ($expected !== null && $outputs['revision_ref'] === $expected) {
            throw new DomainException('revision_ref no puede repetir la revisión esperada.');
        }
    }
}
