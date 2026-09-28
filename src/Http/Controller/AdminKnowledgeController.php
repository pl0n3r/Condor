<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Domain\Identity\Entity\User;
use App\Domain\KnowledgeGap\KnowledgeGap;
use App\Shared\Version\AppVersion;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminKnowledgeController extends AbstractController
{
    #[Route('/adminpl0n3r/conocimiento', name: 'app_admin_knowledge', methods: ['GET'])]
    public function __invoke(AppVersion $version): Response
    {
        $this->denyAccessUnlessGranted(User::ROLE_PLATFORM_OWNER);

        $articles = KnowledgeController::canonicalArticles();
        $now = new DateTimeImmutable('now');
        $lifecycle = [
            'draft' => 0,
            'review' => 0,
            'approved' => 0,
            'published' => 0,
            'superseded' => 0,
            'archived' => 0,
        ];
        $stale = 0;

        foreach ($articles as $article) {
            $snapshot = $article->snapshot();
            $state = $snapshot['state'];
            if (is_string($state) && isset($lifecycle[$state])) {
                $lifecycle[$state]++;
            }
            if ($article->isStaleAt($now)) {
                $stale++;
            }
        }

        $gaps = KnowledgeGap::aggregate([]);

        return $this->render('knowledge/admin.html.twig', [
            'app_version' => $version->human(),
            'lifecycle' => $lifecycle,
            'stale_count' => $stale,
            'gap_count' => count($gaps),
            'unanswered_count' => 0,
            'usage_by_channel' => [
                ['channel' => 'help_center', 'status' => 'not_observed', 'count' => null],
                ['channel' => 'contextual_help', 'status' => 'not_observed', 'count' => null],
                ['channel' => 'voice', 'status' => 'not_enabled', 'count' => null],
            ],
        ]);
    }
}
