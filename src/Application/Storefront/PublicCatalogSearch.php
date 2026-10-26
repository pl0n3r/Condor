<?php

declare(strict_types=1);

namespace App\Application\Storefront;

use InvalidArgumentException;

/**
 * Immutable, tenant-neutral input contract. Tenant and publishability are
 * enforced by PublicCatalogPresentation, never by request parameters.
 */
final readonly class PublicCatalogSearch
{
    public const MAX_QUERY_LENGTH = 80;
    public const MAX_CATEGORY_LENGTH = 120;
    public const MAX_PAGE = 1000;

    private ?string $term;
    private ?string $categorySlug;
    private int $page;

    public function __construct(?string $term = null, ?string $categorySlug = null, int $page = 1)
    {
        $term = trim($term ?? '');
        $categorySlug = strtolower(trim($categorySlug ?? ''));

        if (
            mb_strlen($term, 'UTF-8') > self::MAX_QUERY_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/u', $term) === 1
        ) {
            throw new InvalidArgumentException('La búsqueda no es válida.');
        }
        if (
            strlen($categorySlug) > self::MAX_CATEGORY_LENGTH
            || ($categorySlug !== '' && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $categorySlug) !== 1)
        ) {
            throw new InvalidArgumentException('La categoría no es válida.');
        }
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new InvalidArgumentException('La página del catálogo está fuera del rango permitido.');
        }

        $this->term = $term === '' ? null : $term;
        $this->categorySlug = $categorySlug === '' ? null : $categorySlug;
        $this->page = $page;
    }

    /** @param array<string, mixed> $parameters */
    public static function fromQuery(array $parameters): self
    {
        $term = $parameters['q'] ?? '';
        $category = $parameters['categoria'] ?? '';
        $page = $parameters['pagina'] ?? $parameters['page'] ?? '1';
        if (!is_string($term) || !is_string($category) || !is_string($page)
            || preg_match('/^[1-9][0-9]{0,3}$/D', $page) !== 1
            || (int) $page > self::MAX_PAGE) {
            throw new InvalidArgumentException('Los filtros del catálogo no son válidos.');
        }

        return new self($term, $category, (int) $page);
    }

    public function term(): ?string
    {
        return $this->term;
    }

    public function categorySlug(): ?string
    {
        return $this->categorySlug;
    }

    public function page(): int
    {
        return $this->page;
    }

    /** @return array{q:string,categoria:string} */
    public function filters(): array
    {
        return ['q' => $this->term ?? '', 'categoria' => $this->categorySlug ?? ''];
    }

    public function pageHref(int $page): string
    {
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new InvalidArgumentException('La página del catálogo está fuera del rango permitido.');
        }

        $query = [];
        if ($this->term !== null) {
            $query['q'] = $this->term;
        }
        if ($this->categorySlug !== null) {
            $query['categoria'] = $this->categorySlug;
        }
        $query['pagina'] = $page;

        return '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
