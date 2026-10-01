<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Cms\Entity\CmsBlock;
use App\Domain\Cms\Entity\CmsPage;
use App\Domain\Cms\Entity\CmsTheme;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use App\Tests\Support\BrowserCsrfToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CmsAdminControllerTest extends WebTestCase
{
    use BrowserCsrfToken;

    public function testAuthorizedStaffCanEditDraftAndPublishContent(): void
    {
        $client = self::createClient();
        $fixture = $this->fixture($this->em());

        $client->loginUser($fixture['editor']);
        $client->request('GET', '/admin/sitio');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#condor-admin-root[data-section="site"]');
        $token = $this->csrfToken($client, 'branch_access');

        $client->jsonRequest(
            'PATCH',
            $this->pagePath($fixture).'/'.$fixture['page']->id(),
            [
                'theme_id' => $fixture['theme']->id(),
                'slug' => 'inicio-renovado',
                'title' => 'Inicio renovado',
            ],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );
        self::assertResponseIsSuccessful();
        $page = $this->json($client)['page'];
        self::assertSame('draft', $page['status']);
        self::assertSame('Inicio renovado', $page['title']);
        self::assertSame('inicio-renovado', $page['slug']);

        $client->jsonRequest(
            'PATCH',
            $this->pagePath($fixture).'/'.$fixture['page']->id().
                '/blocks/'.$fixture['block']->id(),
            [
                'type' => 'hero',
                'payload' => ['headline' => 'Nueva portada'],
                'sort_order' => 2,
            ],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );
        self::assertResponseIsSuccessful();
        $block = $this->json($client)['block'];
        self::assertSame('hero', $block['type']);
        self::assertSame(['headline' => 'Nueva portada'], $block['payload']);
        self::assertSame(2, $block['sort_order']);

        $client->request(
            'POST',
            $this->pagePath($fixture).'/'.$fixture['page']->id().'/publish',
            [],
            [],
            ['HTTP_X_CSRF_TOKEN' => $token],
        );
        self::assertResponseIsSuccessful();
        $published = $this->json($client)['page'];
        self::assertSame('published', $published['status']);
        self::assertIsString($published['published_at']);
        self::assertNotSame('', $published['published_at']);
    }

    public function testPermissionsTenantAndCsrfAreEnforced(): void
    {
        $client = self::createClient();
        $fixture = $this->fixture($this->em());

        $client->loginUser($fixture['viewer']);
        $viewerToken = $this->token($client);
        $client->request('GET', $this->pagePath($fixture));
        self::assertResponseIsSuccessful();

        $client->jsonRequest(
            'PATCH',
            $this->pagePath($fixture).'/'.$fixture['page']->id(),
            [
                'theme_id' => $fixture['theme']->id(),
                'slug' => 'no-autorizado',
                'title' => 'No autorizado',
            ],
            ['HTTP_X_CSRF_TOKEN' => $viewerToken],
        );
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($fixture['editor']);
        $client->jsonRequest(
            'PATCH',
            $this->pagePath($fixture).'/'.$fixture['page']->id(),
            [
                'theme_id' => $fixture['theme']->id(),
                'slug' => 'sin-csrf',
                'title' => 'Sin CSRF',
            ],
        );
        self::assertResponseStatusCodeSame(403);

        $editorToken = $this->token($client);
        $client->jsonRequest(
            'PATCH',
            $this->pagePath($fixture).'/'.$fixture['foreign_page']->id(),
            [
                'theme_id' => $fixture['foreign_theme']->id(),
                'slug' => 'intrusa',
                'title' => 'Intrusa',
            ],
            ['HTTP_X_CSRF_TOKEN' => $editorToken],
        );
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array{
     *   tenant: Tenant,
     *   branch: Branch,
     *   editor: User,
     *   viewer: User,
     *   theme: CmsTheme,
     *   page: CmsPage,
     *   block: CmsBlock,
     *   foreign_theme: CmsTheme,
     *   foreign_page: CmsPage
     * }
     */
    private function fixture(EntityManagerInterface $em): array
    {
        $suffix = strtolower(bin2hex(random_bytes(4)));
        $tenant = new Tenant('CMS '.$suffix, 'cms-'.$suffix);
        $branch = new Branch($tenant, 'Principal', 'principal', null, true);
        $editor = new User('cms-editor-'.$suffix.'@example.test', 'Editor CMS');
        $viewer = new User('cms-viewer-'.$suffix.'@example.test', 'Lector CMS');
        $editorMembership = new Membership($tenant, $editor, 'ADMIN');
        $viewerMembership = new Membership($tenant, $viewer, 'ADMIN');
        $editorRole = new Role(
            $tenant,
            'Editor sitio '.$suffix,
            ['site.view', 'site.update'],
        );
        $viewerRole = new Role(
            $tenant,
            'Lector sitio '.$suffix,
            ['site.view'],
        );
        $theme = new CmsTheme($tenant, 'base', 'Base');
        $page = new CmsPage($tenant, $theme, 'inicio', 'Inicio');
        $block = new CmsBlock(
            $tenant,
            $page,
            'text',
            ['text' => 'Contenido inicial'],
            0,
        );

        $foreignTenant = new Tenant('Otro CMS '.$suffix, 'otro-cms-'.$suffix);
        $foreignTheme = new CmsTheme($foreignTenant, 'base', 'Base');
        $foreignPage = new CmsPage(
            $foreignTenant,
            $foreignTheme,
            'inicio',
            'Inicio ajeno',
        );

        foreach ([
            $tenant,
            $branch,
            $editor,
            $viewer,
            $editorMembership,
            $viewerMembership,
            $editorRole,
            $viewerRole,
            new BranchRoleAssignment($editorMembership, $branch, $editorRole),
            new BranchRoleAssignment($viewerMembership, $branch, $viewerRole),
            $theme,
            $page,
            $block,
            $foreignTenant,
            $foreignTheme,
            $foreignPage,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return [
            'tenant' => $tenant,
            'branch' => $branch,
            'editor' => $editor,
            'viewer' => $viewer,
            'theme' => $theme,
            'page' => $page,
            'block' => $block,
            'foreign_theme' => $foreignTheme,
            'foreign_page' => $foreignPage,
        ];
    }

    /** @param array{branch: Branch} $fixture */
    private function pagePath(array $fixture): string
    {
        return '/api/v1/branches/'.$fixture['branch']->id().'/cms/pages';
    }

    private function token(KernelBrowser $client): string
    {
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        return $this->csrfToken($client, 'branch_access');
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $payload = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($payload);

        return $payload;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
