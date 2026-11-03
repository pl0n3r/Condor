<?php

declare(strict_types=1);

namespace App\Tests\Domain\AI;

use App\Domain\AI\AiTenantContext;
use App\Domain\AI\AiToolPolicy;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AiTenantContextTest extends TestCase
{
    /**
     * @param array<string, mixed> $override
     */
    private function build(array $override = []): AiTenantContext
    {
        $context = $override + [
            'tenant_id' => 'acme-1',
            'tool' => 'catalog.read',
            'knowledge_refs' => ['knowledge:faq', 'knowledge:returns'],
        ];

        return AiTenantContext::fromArray($context, new AiToolPolicy());
    }

    public function testBuildsFromACanonicalContextAndExposesEveryField(): void
    {
        $context = $this->build();

        self::assertSame('acme-1', $context->tenantId());
        self::assertSame('catalog.read', $context->tool());
        self::assertSame(['knowledge:faq', 'knowledge:returns'], $context->knowledgeRefs());
        self::assertSame(
            ['tenant_id' => 'acme-1', 'tool' => 'catalog.read', 'knowledge_refs' => ['knowledge:faq', 'knowledge:returns']],
            $context->snapshot(),
        );
    }

    public function testTenantAndToolAreTrimmed(): void
    {
        $context = $this->build(['tenant_id' => '  acme-1 ', 'tool' => ' catalog.read ']);

        self::assertSame('acme-1', $context->tenantId());
        self::assertSame('catalog.read', $context->tool());
    }

    public function testAnEmptyKnowledgeScopeIsAllowed(): void
    {
        self::assertSame([], $this->build(['knowledge_refs' => []])->knowledgeRefs());
    }

    public function testRejectsAContextWithoutExactlyTheCanonicalKeys(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Contexto de IA no canónico.');

        AiTenantContext::fromArray(['tenant_id' => 'acme-1', 'tool' => 'catalog.read'], new AiToolPolicy());
    }

    public function testRejectsAContextWithAnExtraKey(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Contexto de IA no canónico.');

        $this->build(['extra' => 'x']);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function wrongTypes(): iterable
    {
        yield 'tenant_id not a string' => ['tenant_id', 7];
        yield 'tool not a string' => ['tool', null];
        yield 'knowledge_refs not an array' => ['knowledge_refs', 'knowledge:faq'];
    }

    /**
     * @dataProvider wrongTypes
     */
    public function testRejectsFieldsOfTheWrongType(string $field, mixed $value): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Contexto de IA inválido.');

        $this->build([$field => $value]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTenants(): iterable
    {
        yield 'empty' => [''];
        yield 'only spaces' => ['   '];
        yield 'starts with a separator' => ['-acme'];
        yield 'illegal character' => ['acme/1'];
        yield 'inner newline' => ["acme\n1"];
        yield 'longer than 128 characters' => [str_repeat('a', 129)];
    }

    /**
     * @dataProvider invalidTenants
     */
    public function testRejectsInvalidTenantIds(string $tenantId): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Tenant de contexto IA inválido.');

        $this->build(['tenant_id' => $tenantId]);
    }

    public function testAcceptsATenantIdAtTheMaximumLength(): void
    {
        $tenantId = str_repeat('a', 128);

        self::assertSame($tenantId, $this->build(['tenant_id' => $tenantId])->tenantId());
    }

    public function testRejectsAToolThePolicyDoesNotAuthorize(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Tool de IA no autorizada.');

        $this->build(['tool' => 'catalog.delete']);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function invalidKnowledgeRefs(): iterable
    {
        yield 'not a string' => [[5]];
        yield 'missing prefix' => [['faq']];
        yield 'wrong prefix' => [['evidence:faq']];
        yield 'starts with a separator' => [['knowledge:-faq']];
        yield 'illegal character' => [['knowledge:faq returns']];
        yield 'longer than 160 characters' => [['knowledge:' . str_repeat('a', 151)]];
    }

    /**
     * @param array<mixed> $refs
     * @dataProvider invalidKnowledgeRefs
     */
    public function testRejectsInvalidKnowledgeRefs(array $refs): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Knowledge ref de IA inválida.');

        $this->build(['knowledge_refs' => $refs]);
    }

    public function testRejectsDuplicatedKnowledgeRefs(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Knowledge scope de IA duplicado.');

        $this->build(['knowledge_refs' => ['knowledge:faq', 'knowledge:faq']]);
    }

    public function testRejectsAKnowledgeScopeThatIsNotAList(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Knowledge scope de IA inválido.');

        $this->build(['knowledge_refs' => ['a' => 'knowledge:faq']]);
    }

    public function testAcceptsExactlyThirtyTwoKnowledgeRefsButNotThirtyThree(): void
    {
        $refs = array_map(static fn (int $i): string => 'knowledge:r' . $i, range(1, 32));
        self::assertCount(32, $this->build(['knowledge_refs' => $refs])->knowledgeRefs());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Knowledge scope de IA inválido.');

        $this->build(['knowledge_refs' => [...$refs, 'knowledge:r33']]);
    }

    public function testRebindingTheSameTenantReturnsTheSameContext(): void
    {
        $context = $this->build();

        self::assertSame($context, $context->rebindTenant(' acme-1 '));
    }

    public function testRebindingADifferentTenantIsRefused(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Rebind cross-tenant no autorizado.');

        $this->build()->rebindTenant('other-tenant');
    }

    public function testRebindingAnInvalidTenantIsRefusedAsInvalidNotAsCrossTenant(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Tenant de contexto IA inválido.');

        $this->build()->rebindTenant('');
    }
}
