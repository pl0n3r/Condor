<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Cms\Entity\CmsBlock;
use App\Domain\Cms\Entity\CmsPage;
use App\Infrastructure\Tenancy\TenantContext;
use App\Infrastructure\Tenancy\TenantResolver;
use App\Shared\Version\AppVersion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class CmsPublicController extends AbstractController
{
    #[Route(
        '/{tenant_slug}/{page_slug}',
        name: 'app_cms_public',
        requirements: [
            'tenant_slug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
            'page_slug' => '[a-z0-9]+(?:-[a-z0-9]+)*',
        ],
        methods: ['GET'],
        priority: -120,
    )]
    public function __invoke(
        string $tenant_slug,
        string $page_slug,
        Request $request,
        TenantContext $tenantContext,
        EntityManagerInterface $entityManager,
        AppVersion $version,
    ): Response {
        if (!TenantResolver::isPlatformHost($request->getHost())) {
            throw new NotFoundHttpException();
        }

        $tenant = $tenantContext->current();
        if (!$tenant instanceof \App\Domain\Organization\Entity\Tenant || $tenant->slug() !== $tenant_slug) {
            throw new NotFoundHttpException();
        }

        $page = $entityManager->getRepository(CmsPage::class)->findOneBy([
            'tenant' => $tenant,
            'slug' => $page_slug,
            'status' => CmsPage::STATUS_PUBLISHED,
        ]);
        if (!$page instanceof CmsPage) {
            throw new NotFoundHttpException();
        }

        /** @var list<CmsBlock> $blocks */
        $blocks = $entityManager->getRepository(CmsBlock::class)->findBy(
            ['tenant' => $tenant, 'page' => $page],
            ['sortOrder' => 'ASC', 'id' => 'ASC'],
        );

        $canonical = $this->generateUrl(
            'app_cms_public',
            [
                'tenant_slug' => $tenant->slug(),
                'page_slug' => $page->slug(),
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $viewBlocks = $this->viewBlocks($blocks);
        $response = $this->render('cms/public_page.html.twig', [
            'app_version' => $version->human(),
            'tenant' => $tenant,
            'page' => $page,
            'blocks' => $viewBlocks,
            'canonical' => $canonical,
            'description' => $this->description($viewBlocks),
        ]);

        $response->setPublic();
        $response->setMaxAge(60);
        $response->setEtag($this->etag($page, $blocks));
        if ($page->publishedAt() instanceof \DateTimeImmutable) {
            $response->setLastModified($page->publishedAt());
        }
        $response->isNotModified($request);

        return $response;
    }

    /**
     * @param list<CmsBlock> $blocks
     *
     * @return list<array<string, string>>
     */
    private function viewBlocks(array $blocks): array
    {
        $view = [];

        foreach ($blocks as $block) {
            $payload = $block->payload();
            $item = ['type' => $block->type()];

            foreach (['headline', 'subheadline', 'text', 'label', 'alt'] as $key) {
                $value = $this->stringValue($payload, $key);
                if ($value !== null) {
                    $item[$key] = $value;
                }
            }

            foreach (['href', 'src'] as $key) {
                $value = $this->safeUrl($this->stringValue($payload, $key));
                if ($value !== null) {
                    $item[$key] = $value;
                }
            }

            $cta = $payload['cta'] ?? null;
            if (is_array($cta)) {
                $label = $this->stringValue($cta, 'label');
                $href = $this->safeUrl($this->stringValue($cta, 'href'));
                if ($label !== null && $href !== null) {
                    $item['cta_label'] = $label;
                    $item['cta_href'] = $href;
                }
            }

            $view[] = $item;
        }

        return $view;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function stringValue(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function safeUrl(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return $value;
        }

        return preg_match('#^https://#i', $value) === 1 ? $value : null;
    }

    /**
     * @param list<array<string, string>> $blocks
     */
    private function description(array $blocks): ?string
    {
        foreach ($blocks as $block) {
            foreach (['subheadline', 'text', 'headline'] as $key) {
                $value = $block[$key] ?? null;
                if (!is_string($value)) {
                    continue;
                }

                $plain = trim(strip_tags($value));
                if ($plain !== '') {
                    return mb_substr($plain, 0, 160, 'UTF-8');
                }
            }
        }

        return null;
    }

    /** @param list<CmsBlock> $blocks */
    private function etag(CmsPage $page, array $blocks): string
    {
        $fingerprint = [
            'page' => [
                $page->id(),
                $page->slug(),
                $page->title(),
                $page->publishedAt()?->format(DATE_ATOM),
            ],
            'blocks' => array_map(
                static fn (CmsBlock $block): array => [
                    $block->id(),
                    $block->type(),
                    $block->payload(),
                    $block->sortOrder(),
                ],
                $blocks,
            ),
        ];

        return hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));
    }
}
