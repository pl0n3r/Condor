<?php

declare(strict_types=1);

namespace App\Tests\Application\Observability;

use App\Application\Observability\CapabilityReleaseGate;
use PHPUnit\Framework\TestCase;

final class CapabilityReleaseGateTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../..';

    public function testRealRegistryHasNoUnlinkedGapP0(): void
    {
        self::assertSame([], CapabilityReleaseGate::violations(self::ROOT));
    }

    public function testGapWithoutIssueFailsTheRelease(): void
    {
        $registry = "| GAP-P0-99 | Algo | sin issue | docs/runbooks/conciliacion-ledger.md |\n";

        self::assertSame(['GAP-P0-99: sin Issue enlazado'], CapabilityReleaseGate::evaluate($registry, self::ROOT));
    }

    public function testGapWithMissingOrEscapingRunbookFailsTheRelease(): void
    {
        $missing = "| GAP-P0-98 | Algo | #1 | docs/runbooks/no-existe.md |\n";
        $escape = "| GAP-P0-97 | Algo | #1 | docs/runbooks/../../composer.json |\n";

        self::assertSame(['GAP-P0-98: sin runbook verificable'], CapabilityReleaseGate::evaluate($missing, self::ROOT));
        self::assertSame(['GAP-P0-97: sin runbook verificable'], CapabilityReleaseGate::evaluate($escape, self::ROOT));
    }

    public function testEmptyRegistryFailsClosed(): void
    {
        self::assertNotSame([], CapabilityReleaseGate::evaluate("# vacío\n", self::ROOT));
        self::assertNotSame([], CapabilityReleaseGate::violations('/ruta/inexistente'));
    }
}
