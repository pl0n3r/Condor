<?php

declare(strict_types=1);

namespace App\Tests\Application\Storefront;

use App\Application\Storefront\PublicCatalogSearch;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PublicCatalogSearchTest extends TestCase
{
    public function testFiltersAreBoundedAndPaginationLinksPreserveTheirValues(): void
    {
        $search = PublicCatalogSearch::fromQuery([
            'q' => '  Camisa azul  ',
            'categoria' => 'ROPA-HOMBRE',
            'pagina' => '2',
        ]);

        self::assertSame('Camisa azul', $search->term());
        self::assertSame('ropa-hombre', $search->categorySlug());
        self::assertSame(2, $search->page());
        self::assertSame(
            '?q=Camisa%20azul&categoria=ropa-hombre&pagina=3',
            $search->pageHref(3),
        );
        self::assertSame(['q' => 'Camisa azul', 'categoria' => 'ropa-hombre'], $search->filters());
        self::assertSame(5, PublicCatalogSearch::fromQuery(['page' => '5'])->page());
    }

    public function testLiteralTermNeverProducesSqlWildcardsOrExecutableMarkup(): void
    {
        $search = PublicCatalogSearch::fromQuery(['q' => '%_ <script>', 'pagina' => '1']);
        self::assertSame('%_ <script>', $search->term());
        self::assertSame('?q=%25_%20%3Cscript%3E&pagina=2', $search->pageHref(2));
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueryFailsClosed(array $query): void
    {
        $this->expectException(InvalidArgumentException::class);
        PublicCatalogSearch::fromQuery($query);
    }

    /** @return iterable<string, array{0:array<string,mixed>}> */
    public static function invalidQueries(): iterable
    {
        yield 'array q' => [['q' => ['unexpected']]];
        yield 'array categoria' => [['categoria' => ['ropa']]];
        yield 'array page' => [['pagina' => ['1']]];
        yield 'page zero' => [['pagina' => '0']];
        yield 'page too high' => [['pagina' => '1001']];
        yield 'page injection' => [['pagina' => '1 OR 1=1']];
        yield 'term too long' => [['q' => str_repeat('a', 81)]];
        yield 'control character' => [['q' => "one\ntwo"]];
        yield 'category injection' => [['categoria' => '../otra-empresa']];
    }
}
