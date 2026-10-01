<?php

declare(strict_types=1);

namespace App\Application\Cms;

use App\Domain\Cms\Entity\CmsBlock;
use App\Domain\Cms\Entity\CmsPage;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;

final readonly class PublicCmsPresentation
{
    private const MAX_TITLE_LENGTH = 200;
    private const MAX_TEXT_LENGTH = 5000;
    private const MAX_ALT_LENGTH = 300;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return array<string, mixed>|null */
    public function publishedPage(Tenant $tenant, string $slug): ?array
    {
        $page = $this->entityManager->getRepository(CmsPage::class)->findOneBy([
            'tenant' => $tenant,
            'slug' => strtolower(trim($slug)),
            'status' => CmsPage::STATUS_PUBLISHED,
        ]);
        if (!$page instanceof CmsPage) {
            return null;
        }

        $blocks = array_values(array_filter(
            $this->entityManager->getRepository(CmsBlock::class)->findBy(
                ['tenant' => $tenant, 'page' => $page],
                ['sortOrder' => 'ASC', 'id' => 'ASC'],
            ),
            static fn (mixed $block): bool => $block instanceof CmsBlock,
        ));

        $publicBlocks = [];
        foreach ($blocks as $block) {
            $presented = self::presentBlock($block);
            if ($presented !== null) {
                $publicBlocks[] = $presented;
            }
        }

        return [
            'id' => $page->id(),
            'slug' => $page->slug(),
            'title' => $page->title(),
            'theme' => $page->theme()->key(),
            'published_at' => $page->publishedAt()?->format(DATE_ATOM),
            'blocks' => $publicBlocks,
            'etag' => self::etag($page, $blocks),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function presentBlock(CmsBlock $block): ?array
    {
        $payload = $block->payload();

        return match ($block->type()) {
            'text' => self::textBlock($block, $payload),
            'image' => self::imageBlock($block, $payload),
            'hero' => self::heroBlock($block, $payload),
            'cta' => self::ctaBlock($block, $payload),
            'gallery' => self::galleryBlock($block, $payload),
            'divider' => self::baseBlock($block),
            default => null,
        };
    }

    /** @param array<string, mixed> $payload */
    private static function textBlock(CmsBlock $block, array $payload): ?array
    {
        $text = self::safeText($payload['text'] ?? null, self::MAX_TEXT_LENGTH);
        if ($text === null) {
            return null;
        }

        return self::baseBlock($block) + ['text' => $text];
    }

    /** @param array<string, mixed> $payload */
    private static function imageBlock(CmsBlock $block, array $payload): ?array
    {
        $src = self::safeUrl($payload['src'] ?? null);
        if ($src === null) {
            return null;
        }

        return self::baseBlock($block) + [
            'src' => $src,
            'alt' => self::safeText($payload['alt'] ?? null, self::MAX_ALT_LENGTH) ?? '',
            'caption' => self::safeText($payload['caption'] ?? null, self::MAX_TEXT_LENGTH),
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function heroBlock(CmsBlock $block, array $payload): ?array
    {
        $headline = self::safeText(
            $payload['headline'] ?? $payload['title'] ?? null,
            self::MAX_TITLE_LENGTH,
        );
        $text = self::safeText(
            $payload['text'] ?? $payload['body'] ?? null,
            self::MAX_TEXT_LENGTH,
        );
        $cta = self::safeCta($payload['cta'] ?? null);

        if ($headline === null && $text === null && $cta === null) {
            return null;
        }

        return self::baseBlock($block) + [
            'headline' => $headline,
            'text' => $text,
            'cta' => $cta,
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function ctaBlock(CmsBlock $block, array $payload): ?array
    {
        $cta = self::safeCta($payload);
        if ($cta === null) {
            return null;
        }

        return self::baseBlock($block) + $cta;
    }

    /** @param array<string, mixed> $payload */
    private static function galleryBlock(CmsBlock $block, array $payload): ?array
    {
        $images = $payload['images'] ?? null;
        if (!is_array($images)) {
            return null;
        }

        $publicImages = [];
        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }
            $src = self::safeUrl($image['src'] ?? null);
            if ($src === null) {
                continue;
            }
            $publicImages[] = [
                'src' => $src,
                'alt' => self::safeText($image['alt'] ?? null, self::MAX_ALT_LENGTH) ?? '',
            ];
        }
        if ($publicImages === []) {
            return null;
        }

        return self::baseBlock($block) + ['images' => $publicImages];
    }

    /** @return array{id:string,type:string,sort_order:int} */
    private static function baseBlock(CmsBlock $block): array
    {
        return [
            'id' => $block->id(),
            'type' => $block->type(),
            'sort_order' => $block->sortOrder(),
        ];
    }

    /** @return array{label:string,href:string}|null */
    private static function safeCta(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $label = self::safeText($value['label'] ?? null, self::MAX_TITLE_LENGTH);
        $href = self::safeUrl($value['href'] ?? null);
        if ($label === null || $href === null) {
            return null;
        }

        return ['label' => $label, 'href' => $href];
    }

    private static function safeText(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maxLength) {
            return null;
        }
        if (preg_match('/<[^>]*>/', $value) === 1) {
            return null;
        }

        return $value;
    }

    private static function safeUrl(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return $value;
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = $parts['host'] ?? null;
        if (!in_array($scheme, ['http', 'https'], true) || !is_string($host) || $host === '') {
            return null;
        }

        return $value;
    }

    /** @param list<CmsBlock> $blocks */
    private static function etag(CmsPage $page, array $blocks): string
    {
        $fingerprint = [
            'page' => [
                $page->id(),
                $page->slug(),
                $page->title(),
                $page->theme()->id(),
                $page->publishedAt()?->format(DATE_ATOM),
            ],
            'blocks' => array_map(
                static fn (CmsBlock $block): array => [
                    $block->id(),
                    $block->type(),
                    $block->sortOrder(),
                    $block->payload(),
                ],
                $blocks,
            ),
        ];

        try {
            return hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return hash('sha256', $page->id().':'.$page->slug());
        }
    }
}
