<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Observability\DiagnosticShareService;
use App\Application\Observability\FunctionalSignalReport;
use App\Domain\Identity\Entity\User;
use App\Domain\Observability\Entity\DiagnosticShare;
use App\Domain\Observability\Entity\ErrorIncident;
use App\Infrastructure\Observability\FatalLog;
use App\Infrastructure\Observability\RequestMetrics;
use App\Shared\Version\AppVersion;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PlatformDiagnosticsController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/adminpl0n3r/diagnosticos', name: 'app_platform_diagnostics', methods: ['GET'])]
    public function index(
        EntityManagerInterface $entityManager,
        AppVersion $version,
        Request $request,
    ): Response {
        $this->denyAccessUnlessGranted(User::ROLE_PLATFORM_OWNER);

        $incidents = $entityManager->getRepository(ErrorIncident::class)->findBy(
            [],
            ['occurredAt' => 'DESC'],
            50,
        );
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $shares = array_values(array_filter(
            $entityManager->getRepository(DiagnosticShare::class)->findBy(
                ['revokedAt' => null],
                ['createdAt' => 'DESC'],
                25,
            ),
            static fn (DiagnosticShare $share): bool => $share->isUsable($now),
        ));

        $fatalLogUrl = sprintf(
            '%s://%s/platform-fatal-log.php?token=%s',
            $request->getScheme(),
            $request->getHttpHost(),
            FatalLog::accessToken($this->appSecret),
        );

        return $this->render('platform_owner/diagnostics.html.twig', [
            'app_version' => $version->human(),
            'incidents' => $incidents,
            'shares' => $shares,
            'fatal_log_url' => $fatalLogUrl,
            'request_metrics' => RequestMetrics::summary($this->projectDir),
        ]);
    }

    #[Route(
        '/adminpl0n3r/reportes/actividad.csv',
        name: 'app_platform_activity_report_csv',
        methods: ['GET'],
    )]
    public function activityReportCsv(FunctionalSignalReport $report): Response
    {
        $this->denyAccessUnlessGranted(User::ROLE_PLATFORM_OWNER);

        $response = new Response($report->toCsv(30));
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="condor-actividad-funcional-30d.csv"',
        );
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route(
        '/adminpl0n3r/diagnosticos/{id}/compartir',
        name: 'app_platform_diagnostic_share_create',
        requirements: ['id' => '[0-9A-HJKMNP-TV-Z]{26}'],
        methods: ['POST'],
    )]
    public function createShare(
        string $id,
        Request $request,
        EntityManagerInterface $entityManager,
        DiagnosticShareService $shares,
        AppVersion $version,
    ): Response {
        $this->denyAccessUnlessGranted(User::ROLE_PLATFORM_OWNER);

        $incident = $entityManager->getRepository(ErrorIncident::class)->find($id);
        if (!$incident instanceof ErrorIncident) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid(
            'diagnostic_share_'.$incident->id(),
            (string) $request->request->get('_token'),
        )) {
            throw $this->createAccessDeniedException();
        }

        $created = $shares->create($incident);
        $url = $this->generateUrl(
            'app_shared_diagnostic',
            ['token' => $created['token']],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->render('platform_owner/diagnostic_share.html.twig', [
            'app_version' => $version->human(),
            'share' => $created['share'],
            'share_url' => $url,
        ]);
    }

    #[Route(
        '/adminpl0n3r/diagnosticos/compartidos/{id}/revocar',
        name: 'app_platform_diagnostic_share_revoke',
        requirements: ['id' => '[0-9A-HJKMNP-TV-Z]{26}'],
        methods: ['POST'],
    )]
    public function revokeShare(
        string $id,
        Request $request,
        EntityManagerInterface $entityManager,
        DiagnosticShareService $shares,
    ): Response {
        $this->denyAccessUnlessGranted(User::ROLE_PLATFORM_OWNER);

        $share = $entityManager->getRepository(DiagnosticShare::class)->find($id);
        if (!$share instanceof DiagnosticShare) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid(
            'diagnostic_revoke_'.$share->id(),
            (string) $request->request->get('_token'),
        )) {
            throw $this->createAccessDeniedException();
        }

        $shares->revoke($share);

        return $this->redirectToRoute('app_platform_diagnostics');
    }
}
