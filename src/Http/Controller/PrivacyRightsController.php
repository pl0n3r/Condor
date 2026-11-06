<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\Tenant;
use App\Shared\Version\AppVersion;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

// Public-controller-info CLI contract loads this class without Composer autoload.
require_once __DIR__.'/BranchApiSupport.php';

/**
 * Página informativa sin formularios ni tratamiento de solicitudes reales.
 * La identidad y el canal provienen de datos.yml, nunca del request.
 */
final class PrivacyRightsController extends AbstractController
{
    use BranchApiSupport;
    public const PLACEHOLDER = '[COMPLETAR POR EL DUEÑO]';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/privacidad-y-mis-derechos', name: 'app_privacy_rights', methods: ['GET'])]
    public function __invoke(AppVersion $version): Response
    {
        return $this->render('privacidad/derechos.html.twig', [
            'app_version' => $version->human(),
            'responsable' => self::publicControllerInfo($this->projectDir.'/datos.yml'),
            'pendiente' => self::PLACEHOLDER,
        ]);
    }
    // M14-S08: pre-live, in-session demonstration only. Never accept PII,
    // issue a legal acknowledgement, dispatch email or persist a rights request.
    #[Route('/api/v1/branches/{branchId}/privacy/requests', name: 'api_privacy_synthetic_list', methods: ['GET'])]
    public function listSynthetic(string $branchId, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'settings.view');
        $this->rejectQuery($request);
        $rows = $this->records($request, $tenant, $branch);
        return $this->privateJson([
            'state' => $rows === [] ? 'empty' : 'ready',
            'requests' => array_values($rows),
            'synthetic_only' => true,
            'legal_review' => 'pending',
        ]);
    }
    #[Route('/api/v1/branches/{branchId}/privacy/requests', name: 'api_privacy_synthetic_create', methods: ['POST'])]
    public function createSynthetic(string $branchId, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'settings.create');
        $this->requireCsrf($request);
        $this->rejectQuery($request);
        $body = $this->payload($request, ['kind', 'subject_ref']);
        $kind = $body['kind'] ?? null;
        $subject = $body['subject_ref'] ?? null;
        if (!is_string($kind) || !in_array($kind, ['access', 'rectification', 'deletion'], true)
            || !is_string($subject)
            || preg_match('~\Afixture:subject/[a-z][a-z0-9_-]{0,31}\z~D', $subject) !== 1
        ) {
            throw new UnprocessableEntityHttpException('Solo se admiten referencias ficticias y tipos conocidos.');
        }
        $rows = $this->records($request, $tenant, $branch);
        if (count($rows) >= 24) {
            throw new UnprocessableEntityHttpException('Límite de pruebas ficticias alcanzado.');
        }
        $row = [
            'id' => bin2hex(random_bytes(8)), 'kind' => $kind, 'subject_ref' => $subject,
            'status' => 'pending_review',
        ];
        $rows[$row['id']] = $row;
        $request->getSession()->set($this->sessionKey($tenant, $branch), $rows);
        return $this->privateJson([
            'request' => $row, 'synthetic_only' => true,
            'persisted' => false, 'dispatched' => false, 'legal_review' => 'pending',
        ], Response::HTTP_CREATED);
    }
    #[Route('/api/v1/branches/{branchId}/privacy/requests/{id}', name: 'api_privacy_synthetic_view', methods: ['GET'])]
    public function viewSynthetic(string $branchId, string $id, Request $request): JsonResponse
    {
        $this->syntheticOnly();
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'settings.view');
        $this->rejectQuery($request);
        if (preg_match('/\A[a-f0-9]{16}\z/D', $id) !== 1) {
            throw new NotFoundHttpException('Solicitud ficticia no encontrada.');
        }
        $rows = $this->records($request, $tenant, $branch);
        if (!isset($rows[$id])) {
            throw new NotFoundHttpException('Solicitud ficticia no encontrada.');
        }
        return $this->privateJson([
            'request' => $rows[$id], 'synthetic_only' => true, 'legal_review' => 'pending',
        ]);
    }
    private function syntheticOnly(): void
    {
        $environment = $this->getParameter('kernel.environment');
        if ($environment !== 'test' && ($environment !== 'dev'
            || getenv('CONDOR_PRIVACY_SYNTHETIC_API') !== '1'
            || getenv('CONDOR_PRODUCTION_STAGE') !== 'construccion'
        )) {
            throw new NotFoundHttpException('Privacidad sintética no disponible.');
        }
    }
    private function rejectQuery(Request $request): void
    {
        if ($request->query->all() !== []) {
            throw new UnprocessableEntityHttpException('No se admiten parámetros de consulta.');
        }
    }

    private function sessionKey(Tenant $tenant, Branch $branch): string
    {
        return 'condor_privacy_synthetic_'.hash('sha256', $tenant->id().'|'.$branch->id());
    }

    /** @return array<string, array{id:string,kind:string,subject_ref:string,status:string}> */
    private function records(Request $request, Tenant $tenant, Branch $branch): array
    {
        $value = $request->getSession()->get($this->sessionKey($tenant, $branch), []);
        // Malformed/stale session data must never be reflected to a tenant.
        if (!is_array($value) || count($value) > 24) {
            throw new NotFoundHttpException('Datos ficticios no disponibles.');
        }
        foreach ($value as $id => $row) {
            if (!is_string($id) || !is_array($row) || ($row['id'] ?? null) !== $id
                || preg_match('/\A[a-f0-9]{16}\z/D', $id) !== 1
                || !in_array($row['kind'] ?? null, ['access', 'rectification', 'deletion'], true)
                || !is_string($row['subject_ref'] ?? null)
                || preg_match('~\Afixture:subject/[a-z][a-z0-9_-]{0,31}\z~D', $row['subject_ref']) !== 1
                || ($row['status'] ?? null) !== 'pending_review'
            ) {
                throw new NotFoundHttpException('Datos ficticios no disponibles.');
            }
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private function privateJson(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = $this->json($data, $status);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }

    /**
     * Lee exclusivamente la configuración local; si está ausente o malformada
     * mantiene marcadores, en vez de publicar una identidad o correo inventado.
     *
     * @return array{name:string,identifier:string,address:string,rights_email:string}
     */
    public static function publicControllerInfo(string $file): array
    {
        $controller = [];
        if (is_file($file) && is_readable($file)) {
            $raw = @file_get_contents($file);
            if (is_string($raw)) {
                try {
                    $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($document) && is_array($document['controller'] ?? null)) {
                        $controller = $document['controller'];
                    }
                } catch (JsonException) {
                    // Un documento inválido jamás habilita un contacto ficticio.
                }
            }
        }

        return [
            'name' => self::safeField($controller['name'] ?? null),
            'identifier' => self::safeField($controller['identifier'] ?? null),
            'address' => self::safeField($controller['address'] ?? null),
            'rights_email' => self::safeField($controller['rights_email'] ?? null, true),
        ];
    }

    private static function safeField(mixed $value, bool $email = false): string
    {
        if (!is_string($value)) {
            return self::PLACEHOLDER;
        }

        $clean = trim($value);
        if ($clean === '' || strlen($clean) > 200
            || preg_match('/[\x00-\x1f\x7f]/', $clean) === 1) {
            return self::PLACEHOLDER;
        }
        if ($email && $clean !== self::PLACEHOLDER
            && filter_var($clean, FILTER_VALIDATE_EMAIL) === false) {
            return self::PLACEHOLDER;
        }

        return $clean;
    }
}
