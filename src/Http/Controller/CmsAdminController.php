<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Cms\Entity\CmsBlock;
use App\Domain\Cms\Entity\CmsPage;
use App\Domain\Cms\Entity\CmsTheme;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class CmsAdminController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(
        '/api/v1/branches/{branchId}/cms/pages',
        name: 'api_cms_admin_pages',
        methods: ['GET'],
    )]
    public function pages(string $branchId): JsonResponse
    {
        [, $tenant] = $this->authorizedScope($branchId, 'site.view');

        $themes = $this->entityManager->getRepository(CmsTheme::class)->findBy(
            ['tenant' => $tenant],
            ['name' => 'ASC'],
        );
        $pages = $this->entityManager->getRepository(CmsPage::class)->findBy(
            ['tenant' => $tenant],
            ['slug' => 'ASC'],
        );

        return $this->json([
            'themes' => array_map(self::themePayload(...), $themes),
            'pages' => array_map(
                fn (CmsPage $page): array => $this->pagePayload($page),
                $pages,
            ),
        ]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/cms/pages/{pageId}',
        name: 'api_cms_admin_page_update',
        methods: ['PATCH'],
    )]
    public function updatePage(
        string $branchId,
        string $pageId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedScope(
            $branchId,
            'site.update',
        );
        $page = $this->page($pageId, $tenant);
        $payload = $this->payload($request, ['theme_id', 'slug', 'title']);
        $theme = $this->theme(
            $this->requiredString($payload, 'theme_id'),
            $tenant,
        );

        $this->domain(fn (): null => self::updatePageEntity(
            $page,
            $theme,
            $this->requiredString($payload, 'slug'),
            $this->requiredString($payload, 'title'),
        ));
        $this->audit(
            $tenant,
            $user,
            'cms_page.updated',
            CmsPage::class,
            $page->id(),
            ['branch_id' => $branch->id()],
        );
        $this->flushUnique('Ya existe una página CMS con ese slug.');

        return $this->json(['page' => $this->pagePayload($page)]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/cms/pages/{pageId}/publish',
        name: 'api_cms_admin_page_publish',
        methods: ['POST'],
    )]
    public function publishPage(
        string $branchId,
        string $pageId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedScope(
            $branchId,
            'site.update',
        );
        $page = $this->page($pageId, $tenant);
        $content = trim((string) $request->getContent());
        if ($content !== '') {
            $payload = $this->payload($request, []);
            if ($payload !== []) {
                throw new UnprocessableEntityHttpException(
                    'Publicar una página no acepta campos adicionales.',
                );
            }
        }

        $page->publish();
        $this->audit(
            $tenant,
            $user,
            'cms_page.published',
            CmsPage::class,
            $page->id(),
            ['branch_id' => $branch->id()],
        );
        $this->entityManager->flush();

        return $this->json(['page' => $this->pagePayload($page)]);
    }

    #[Route(
        '/api/v1/branches/{branchId}/cms/pages/{pageId}/blocks',
        name: 'api_cms_admin_block_create',
        methods: ['POST'],
    )]
    public function createBlock(
        string $branchId,
        string $pageId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedScope(
            $branchId,
            'site.update',
        );
        $page = $this->page($pageId, $tenant);
        $payload = $this->payload(
            $request,
            ['type', 'payload', 'sort_order'],
        );
        $block = $this->domain(fn (): CmsBlock => new CmsBlock(
            $tenant,
            $page,
            $this->requiredString($payload, 'type'),
            $this->requiredObject($payload, 'payload'),
            $this->requiredInt($payload, 'sort_order'),
        ));
        $this->entityManager->persist($block);
        $this->audit(
            $tenant,
            $user,
            'cms_block.created',
            CmsBlock::class,
            $block->id(),
            ['branch_id' => $branch->id(), 'page_id' => $page->id()],
        );
        $this->entityManager->flush();

        return $this->json(
            ['block' => self::blockPayload($block)],
            JsonResponse::HTTP_CREATED,
        );
    }

    #[Route(
        '/api/v1/branches/{branchId}/cms/pages/{pageId}/blocks/{blockId}',
        name: 'api_cms_admin_block_update',
        methods: ['PATCH'],
    )]
    public function updateBlock(
        string $branchId,
        string $pageId,
        string $blockId,
        Request $request,
    ): JsonResponse {
        $this->requireCsrf($request);
        [$user, $tenant, $branch] = $this->authorizedScope(
            $branchId,
            'site.update',
        );
        $page = $this->page($pageId, $tenant);
        $block = $this->block($blockId, $tenant, $page);
        $payload = $this->payload(
            $request,
            ['type', 'payload', 'sort_order'],
        );

        $this->domain(fn (): null => self::updateBlockEntity(
            $block,
            $this->requiredString($payload, 'type'),
            $this->requiredObject($payload, 'payload'),
            $this->requiredInt($payload, 'sort_order'),
        ));
        $this->audit(
            $tenant,
            $user,
            'cms_block.updated',
            CmsBlock::class,
            $block->id(),
            ['branch_id' => $branch->id(), 'page_id' => $page->id()],
        );
        $this->entityManager->flush();

        return $this->json(['block' => self::blockPayload($block)]);
    }

    /** @return array{0: User, 1: Tenant, 2: Branch} */
    private function authorizedScope(
        string $branchId,
        string $permission,
    ): array {
        [$user, $tenant, $branch] = $this->authorizedBranchScope(
            $branchId,
            $permission,
        );

        return [$user, $tenant, $branch];
    }

    private function page(string $pageId, Tenant $tenant): CmsPage
    {
        $page = $this->entityManager->getRepository(CmsPage::class)->findOneBy([
            'id' => $pageId,
            'tenant' => $tenant,
        ]);
        if (!$page instanceof CmsPage) {
            throw new NotFoundHttpException('Página CMS no encontrada.');
        }

        return $page;
    }

    private function theme(string $themeId, Tenant $tenant): CmsTheme
    {
        $theme = $this->entityManager->getRepository(CmsTheme::class)->findOneBy([
            'id' => $themeId,
            'tenant' => $tenant,
        ]);
        if (!$theme instanceof CmsTheme) {
            throw new NotFoundHttpException('Tema CMS no encontrado.');
        }

        return $theme;
    }

    private function block(
        string $blockId,
        Tenant $tenant,
        CmsPage $page,
    ): CmsBlock {
        $block = $this->entityManager->getRepository(CmsBlock::class)->findOneBy([
            'id' => $blockId,
            'tenant' => $tenant,
            'page' => $page,
        ]);
        if (!$block instanceof CmsBlock) {
            throw new NotFoundHttpException('Bloque CMS no encontrado.');
        }

        return $block;
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new UnprocessableEntityHttpException(
                sprintf('El campo %s es obligatorio y debe ser texto.', $field),
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function requiredInt(array $payload, string $field): int
    {
        $value = $payload[$field] ?? null;
        if (!is_int($value)) {
            throw new UnprocessableEntityHttpException(
                sprintf('El campo %s es obligatorio y debe ser entero.', $field),
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function requiredObject(array $payload, string $field): array
    {
        $value = $payload[$field] ?? null;
        if (!is_object($value)) {
            throw new UnprocessableEntityHttpException(
                sprintf('El campo %s es obligatorio y debe ser objeto JSON.', $field),
            );
        }

        return get_object_vars($value);
    }

    private function flushUnique(string $message): void
    {
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictHttpException($message, $exception);
        }
    }

    /** @return array<string, mixed> */
    private function pagePayload(CmsPage $page): array
    {
        $blocks = $this->entityManager->getRepository(CmsBlock::class)->findBy(
            ['tenant' => $page->tenant(), 'page' => $page],
            ['sortOrder' => 'ASC'],
        );

        return [
            'id' => $page->id(),
            'slug' => $page->slug(),
            'title' => $page->title(),
            'status' => $page->status(),
            'published_at' => $page->publishedAt()?->format(DATE_ATOM),
            'theme' => self::themePayload($page->theme()),
            'blocks' => array_map(self::blockPayload(...), $blocks),
        ];
    }

    /** @return array<string, mixed> */
    private static function themePayload(CmsTheme $theme): array
    {
        return [
            'id' => $theme->id(),
            'key' => $theme->key(),
            'name' => $theme->name(),
        ];
    }

    /** @return array<string, mixed> */
    private static function blockPayload(CmsBlock $block): array
    {
        return [
            'id' => $block->id(),
            'type' => $block->type(),
            'payload' => $block->payload(),
            'sort_order' => $block->sortOrder(),
        ];
    }

    private static function updatePageEntity(
        CmsPage $page,
        CmsTheme $theme,
        string $slug,
        string $title,
    ): null {
        $page->updateDraft($theme, $slug, $title);

        return null;
    }

    /** @param array<string, mixed> $payload */
    private static function updateBlockEntity(
        CmsBlock $block,
        string $type,
        array $payload,
        int $sortOrder,
    ): null {
        $block->update($type, $payload, $sortOrder);

        return null;
    }
}
