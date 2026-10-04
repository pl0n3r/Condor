<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;
use Closure;
use DomainException;

final readonly class AiToolRegistry
{
    private const MAX_ENTRIES = 64;

    /**
     * @param array<string, array{
     *     descriptor: AiToolDescriptor,
     *     handler: Closure
     * }> $entries
     */
    private function __construct(
        private AiToolPolicy $policy,
        private array $entries,
    ) {
    }

    /** @param array<mixed> $registrations */
    public static function fromArray(
        AiToolPolicy $policy,
        array $registrations,
    ): self {
        if (!array_is_list($registrations) || count($registrations) > self::MAX_ENTRIES) {
            throw new DomainException('Registro de tools IA inválido.');
        }

        $entries = [];
        foreach ($registrations as $registration) {
            if (!is_array($registration)) {
                throw new DomainException('Registro de tool IA inválido.');
            }

            $keys = array_keys($registration);
            sort($keys);
            if ($keys !== ['descriptor', 'handler', 'tool']) {
                throw new DomainException('Registro de tool IA no canónico.');
            }

            $tool = $registration['tool'];
            $descriptor = $registration['descriptor'];
            $handler = $registration['handler'];

            if (
                !is_string($tool)
                || !$descriptor instanceof AiToolDescriptor
                || !$handler instanceof Closure
            ) {
                throw new DomainException('Registro de tool IA inválido.');
            }

            if ($tool !== $descriptor->tool()) {
                throw new DomainException('Descriptor no corresponde a la tool registrada.');
            }

            $risk = $policy->risk($tool);
            if ($risk !== $descriptor->risk()) {
                throw new DomainException('Descriptor no corresponde a la policy de tools.');
            }

            if (array_key_exists($tool, $entries)) {
                throw new DomainException('Tool IA duplicada en registry.');
            }

            $entries[$tool] = [
                'descriptor' => $descriptor,
                'handler' => $handler,
            ];
        }

        return new self($policy, $entries);
    }

    /**
     * @return array{
     *     descriptor: AiToolDescriptor,
     *     handler: Closure
     * }
     */
    public function resolve(string $tool): array
    {
        $entry = $this->entries[$tool] ?? null;
        if ($entry === null) {
            throw new DomainException('Tool IA no registrada.');
        }

        $descriptor = $entry['descriptor'];
        if (
            $descriptor->tool() !== $tool
            || $this->policy->risk($tool) !== $descriptor->risk()
        ) {
            throw new DomainException('Binding de tool IA inconsistente.');
        }

        return $entry;
    }
}
