<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Domain\AI\AiCommerceReadContract;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;
use Closure;
use DomainException;

final readonly class AiCommerceReadRegistry
{
    private const TOOLS = ['catalog.read', 'inventory.read'];

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
            throw new DomainException('Handlers comerciales read-only incompletos o no canónicos.');
        }

        $contract = new AiCommerceReadContract($policy);
        $registrations = [];

        foreach (self::TOOLS as $tool) {
            $handler = $handlers[$tool] ?? null;
            if (!$handler instanceof Closure) {
                throw new DomainException('Handler comercial debe ser Closure.');
            }

            $descriptor = $tool === 'catalog.read'
                ? $contract->catalogDescriptor()
                : $contract->inventoryDescriptor();

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
            throw new DomainException('Handler comercial devolvió output inválido.');
        }

        return $outputs;
    }

    public function descriptor(string $tool): AiToolDescriptor
    {
        return $this->registry->resolve($tool)['descriptor'];
    }

    private static function validatedHandler(
        AiCommerceReadContract $contract,
        string $tool,
        Closure $handler,
    ): Closure {
        [$validateInputs, $validateOutputs] = match ($tool) {
            'catalog.read' => [
                Closure::fromCallable([$contract, 'validateCatalogInputs']),
                Closure::fromCallable([$contract, 'validateCatalogOutputs']),
            ],
            'inventory.read' => [
                Closure::fromCallable([$contract, 'validateInventoryInputs']),
                Closure::fromCallable([$contract, 'validateInventoryOutputs']),
            ],
            default => throw new DomainException('Tool comercial no soportada.'),
        };

        return static function (array $inputs) use (
            $handler,
            $validateInputs,
            $validateOutputs,
        ): array {
            $validateInputs($inputs);
            $outputs = $handler($inputs);
            if (!is_array($outputs)) {
                throw new DomainException('Handler comercial devolvió output inválido.');
            }
            $validateOutputs($outputs);

            return $outputs;
        };
    }
}
