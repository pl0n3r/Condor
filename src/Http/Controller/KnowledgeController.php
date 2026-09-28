<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\CurrentTenantForUser;
use App\Application\Knowledge\KnowledgeRetrieval;
use App\Domain\Identity\Entity\User;
use App\Domain\Knowledge\KnowledgeArticle;
use App\Shared\Version\AppVersion;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class KnowledgeController extends AbstractController
{
    #[Route('/ayuda', name: 'app_knowledge_help', methods: ['GET'])]
    #[Route('/faq', name: 'app_knowledge_faq', methods: ['GET'])]
    public function publicHelp(Request $request, AppVersion $version): Response
    {
        $module = self::module($request->query->get('module', 'help'));
        $result = KnowledgeRetrieval::retrieve(
            self::canonicalArticles(),
            [
                'visibility' => 'public',
                'locale' => 'es-CO',
                'module' => $module,
                'scope' => 'global',
                'evidence_confidence' => 'sufficient',
                'minimum_sources' => 1,
            ],
            new DateTimeImmutable('now'),
        );

        return $this->render('knowledge/help.html.twig', [
            'app_version' => $version->human(),
            'knowledge' => $result,
            'contextual' => false,
            'tenant_name' => null,
            'module' => $module,
        ]);
    }

    #[Route('/admin/ayuda', name: 'app_knowledge_contextual', methods: ['GET'])]
    public function contextualHelp(
        Request $request,
        AppVersion $version,
        CurrentTenantForUser $currentTenantForUser,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        $tenant = $currentTenantForUser->resolve($user);
        $module = self::module($request->query->get('module', 'admin'));
        $result = KnowledgeRetrieval::retrieve(
            self::canonicalArticles(),
            [
                'visibility' => 'customer',
                'locale' => 'es-CO',
                'module' => $module,
                'scope' => 'tenant:'.$tenant->id(),
                'evidence_confidence' => 'sufficient',
                'minimum_sources' => 1,
            ],
            new DateTimeImmutable('now'),
        );

        return $this->render('knowledge/help.html.twig', [
            'app_version' => $version->human(),
            'knowledge' => $result,
            'contextual' => true,
            'tenant_name' => $tenant->name(),
            'module' => $module,
        ]);
    }

    /** @return list<KnowledgeArticle> */
    public static function canonicalArticles(): array
    {
        return [
            KnowledgeArticle::fromArray([
                'id' => 'account-access',
                'version' => 1,
                'state' => 'published',
                'visibility' => 'public',
                'audience' => 'public',
                'locale' => 'es-CO',
                'scope' => 'global',
                'title' => 'Recuperar acceso a Condor',
                'body' => 'Usa la recuperación de acceso desde el inicio de sesión. Si no reconoces la cuenta o el enlace dejó de ser válido, solicita uno nuevo.',
                'owner_ref' => 'team:support',
                'source_ref' => 'spec:identity-recovery',
                'tags' => ['account', 'security'],
                'modules' => ['help'],
                'product_version_refs' => [],
                'capability_refs' => [],
                'reviewed_at' => '2026-09-01T00:00:00Z',
                'stale_after' => '2099-12-31T23:59:59Z',
            ]),
            KnowledgeArticle::fromArray([
                'id' => 'admin-context',
                'version' => 1,
                'state' => 'published',
                'visibility' => 'customer',
                'audience' => 'customer',
                'locale' => 'es-CO',
                'scope' => 'global',
                'title' => 'Ayuda contextual del administrador',
                'body' => 'La ayuda del administrador usa únicamente conocimiento publicado y autorizado para el contexto activo de la empresa.',
                'owner_ref' => 'team:support',
                'source_ref' => 'spec:knowledge-contextual-help',
                'tags' => ['admin', 'support'],
                'modules' => ['admin'],
                'product_version_refs' => [],
                'capability_refs' => [],
                'reviewed_at' => '2026-09-01T00:00:00Z',
                'stale_after' => '2099-12-31T23:59:59Z',
            ]),
            KnowledgeArticle::fromArray([
                'id' => 'staff-operations',
                'version' => 1,
                'state' => 'published',
                'visibility' => 'staff',
                'audience' => 'staff',
                'locale' => 'es-CO',
                'scope' => 'global',
                'title' => 'Checklist operativo de soporte',
                'body' => 'Referencia interna de soporte pendiente de nueva revisión antes de reutilizarse.',
                'owner_ref' => 'team:support',
                'source_ref' => 'spec:support-operations',
                'tags' => ['operations', 'support'],
                'modules' => ['operations'],
                'product_version_refs' => [],
                'capability_refs' => [],
                'reviewed_at' => '2024-01-01T00:00:00Z',
                'stale_after' => '2025-01-01T00:00:00Z',
            ]),
            KnowledgeArticle::fromArray([
                'id' => 'onboarding-draft',
                'version' => 1,
                'state' => 'draft',
                'visibility' => 'public',
                'audience' => 'public',
                'locale' => 'es-CO',
                'scope' => 'global',
                'title' => 'Primeros pasos',
                'body' => 'Borrador todavía no publicado.',
                'owner_ref' => 'team:product',
                'source_ref' => 'spec:onboarding',
                'tags' => ['onboarding'],
                'modules' => ['help'],
                'product_version_refs' => [],
                'capability_refs' => [],
                'reviewed_at' => null,
                'stale_after' => null,
            ]),
        ];
    }

    private static function module(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new BadRequestHttpException('El módulo solicitado no es válido.');
        }

        return $value;
    }
}
