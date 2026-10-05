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

        foreach (self::TOOLS as $tool) {
            if (!$handlers[$tool] instanceof Closure) {
                throw new DomainException('Handler comercial debe ser Closure.');
            }
        }

        $contract = new AiCommerceReadContract($policy);

        return new self(
            AiToolRegistry::fromArray(
                $policy,
                [
                    [
                        'tool' => 'catalog.read',
                        'descriptor' => $contract->catalogDescriptor(),
                        'handler' => self::catalogHandler(
                            $contract,
                            $handlers['catalog.read'],
                        ),
                    ],
                    [
                        'tool' => 'inventory.read',
                        'descriptor' => $contract->inventoryDescriptor(),
                        'handler' => self::inventoryHandler(
                            $contract,
                            $handlers['inventory.read'],
                        ),
                    ],
                ],
            ),
        );
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

    private static function catalogHandler(
        AiCommerceReadContract $contract,
        Closure $handler,
    ): Closure {
        return static function (array $inputs) use ($contract, $handler): array {
            $contract->validateCatalogInputs($inputs);
            $outputs = $handler($inputs);
            if (!is_array($outputs)) {
                throw new DomainException('Handler catalog.read devolvió output inválido.');
            }
            $contract->validateCatalogOutputs($outputs);

            return $outputs;
        };
    }

    private static function inventoryHandler(
        AiCommerceReadContract $contract,
        Closure $handler,
    ): Closure {
        return static function (array $inputs) use ($contract, $handler): array {
            $contract->validateInventoryInputs($inputs);
            $outputs = $handler($inputs);
            if (!is_array($outputs)) {
                throw new DomainException('Handler inventory.read devolvió output inválido.');
            }
            $contract->validateInventoryOutputs($outputs);

            return $outputs;
        };
    }
}
