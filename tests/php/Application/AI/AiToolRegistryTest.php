<?php

declare(strict_types=1);

namespace App\Tests\Application\AI;

use App\Application\AI\AiToolRegistry;
use App\Domain\AI\AiToolDescriptor;
use App\Domain\AI\AiToolPolicy;
use Closure;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiToolRegistryTest extends TestCase
{
    public function testRegistryResolvesOnlyExactRegisteredDescriptorAndHandler(): void
    {
        $policy = new AiToolPolicy();
        $descriptor = AiToolDescriptor::fromArray(
            $policy,
            [
                'tool' => 'catalog.read',
                'risk' => AiToolPolicy::READ_ONLY,
                'input_names' => ['category_ref', 'limit'],
                'required_inputs' => [],
            ],
        );
        $executions = 0;
        $handler = static function () use (&$executions): array {
            ++$executions;

            return ['status' => 'ok'];
        };

        $registry = AiToolRegistry::fromArray(
            $policy,
            [[
                'tool' => 'catalog.read',
                'descriptor' => $descriptor,
                'handler' => $handler,
            ]],
        );

        $resolved = $registry->resolve('catalog.read');
        self::assertSame($descriptor, $resolved['descriptor']);
        self::assertInstanceOf(Closure::class, $resolved['handler']);
        self::assertSame(['status' => 'ok'], ($resolved['handler'])());
        self::assertSame(1, $executions);
    }

    public function testRegistryReturnsOnlyValidatedInputBinding(): void
    {
        $policy = new AiToolPolicy();
        $descriptor = AiToolDescriptor::fromArray(
            $policy,
            [
                'tool' => 'catalog.read',
                'risk' => AiToolPolicy::READ_ONLY,
                'input_names' => ['category_ref', 'limit'],
                'required_inputs' => ['category_ref'],
            ],
        );
        $executions = 0;
        $handler = static function (array $inputs) use (&$executions): array {
            ++$executions;

            return $inputs;
        };
        $registry = AiToolRegistry::fromArray(
            $policy,
            [[
                'tool' => 'catalog.read',
                'descriptor' => $descriptor,
                'handler' => $handler,
            ]],
        );

        $binding = $registry->resolveWithInputs(
            'catalog.read',
            [
                'category_ref' => 'category:industrial',
                'limit' => 12,
            ],
        );

        self::assertSame($descriptor, $binding['descriptor']);
        self::assertInstanceOf(Closure::class, $binding['handler']);
        self::assertSame(
            [
                'category_ref' => 'category:industrial',
                'limit' => 12,
            ],
            $binding['inputs'],
        );
        self::assertSame(0, $executions);
    }

    public function testRegistryRejectsInvalidInputBindingWithoutExecution(): void
    {
        $policy = new AiToolPolicy();
        $descriptor = AiToolDescriptor::fromArray(
            $policy,
            [
                'tool' => 'catalog.read',
                'risk' => AiToolPolicy::READ_ONLY,
                'input_names' => ['category_ref', 'limit'],
                'required_inputs' => ['category_ref'],
            ],
        );
        $executions = 0;
        $handler = static function (array $inputs = []) use (&$executions): void {
            ++$executions;
        };
        $registry = AiToolRegistry::fromArray(
            $policy,
            [[
                'tool' => 'catalog.read',
                'descriptor' => $descriptor,
                'handler' => $handler,
            ]],
        );

        $cases = [
            ['catalog.read', []],
            ['catalog.read', ['category_ref' => 'category:a', 'unknown' => true]],
            ['inventory.read', ['category_ref' => 'category:a']],
            ['Catalog.Read', ['category_ref' => 'category:a']],
        ];

        foreach ($cases as [$tool, $inputs]) {
            try {
                $registry->resolveWithInputs($tool, $inputs);
                self::fail('El binding de inputs inválido debía fallar cerrado.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame(0, $executions);
    }

    public function testDuplicateUnknownOrDescriptorMismatchFailsClosedWithoutExecution(): void
    {
        $policy = new AiToolPolicy();
        $descriptor = AiToolDescriptor::fromArray(
            $policy,
            [
                'tool' => 'catalog.read',
                'risk' => AiToolPolicy::READ_ONLY,
                'input_names' => ['category_ref'],
                'required_inputs' => [],
            ],
        );
        $executions = 0;
        $handler = static function () use (&$executions): void {
            ++$executions;
        };
        $registration = [
            'tool' => 'catalog.read',
            'descriptor' => $descriptor,
            'handler' => $handler,
        ];

        $invalidRegistrations = [
            [$registration, $registration],
            [[
                'tool' => 'inventory.read',
                'descriptor' => $descriptor,
                'handler' => $handler,
            ]],
            [[
                'tool' => 'catalog.read',
                'descriptor' => $descriptor,
                'handler' => 'strlen',
            ]],
            [[
                'tool' => 'catalog.read',
                'descriptor' => $descriptor,
                'handler' => $handler,
                'extra' => true,
            ]],
        ];

        foreach ($invalidRegistrations as $registrations) {
            try {
                AiToolRegistry::fromArray($policy, $registrations);
                self::fail('El registry inválido debía fallar cerrado.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }

        $registry = AiToolRegistry::fromArray($policy, [$registration]);
        foreach (['inventory.read', 'Catalog.Read', 'unknown.read'] as $tool) {
            try {
                $registry->resolve($tool);
                self::fail('La tool no registrada debía fallar cerrado.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame(0, $executions);
    }
}
