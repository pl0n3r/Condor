<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Identity\BranchAuthorization;
use App\Application\Identity\CurrentTenantForUser;
use App\Application\Seo\TagManagerSettings;
use App\Infrastructure\Persistence\SeoSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Configuration only: no IndexNow submissions and no public GTM rendering.
 * BranchApiSupport resolves the authenticated user's tenant before checking
 * the branch, so neither reads nor writes can cross a tenant boundary.
 */
final class SeoSettingsController extends AbstractController
{
    use BranchApiSupport;

    public function __construct(
        private readonly CurrentTenantForUser $currentTenantForUser,
        private readonly BranchAuthorization $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly SeoSettingsRepository $settings,
    ) {
    }

    #[Route('/api/v1/branches/{branchId}/seo/settings', name: 'api_seo_settings_show', methods: ['GET'])]
    public function show(string $branchId): JsonResponse
    {
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'settings.view');
        $record = $this->settings->snapshot($tenant->id(), $branch->id(), 'web');
        return $this->noStore(self::present($record));
    }

    #[Route('/api/v1/branches/{branchId}/seo/settings', name: 'api_seo_settings_update', methods: ['PUT'])]
    public function update(string $branchId, Request $request): JsonResponse
    {
        $this->requireCsrf($request);
        [, $tenant, $branch] = $this->authorizedBranchScope($branchId, 'settings.update');
        if (strlen((string) $request->getContent()) > 2048) {
            throw new UnprocessableEntityHttpException('Configuración demasiado extensa.');
        }

        $data = $this->payload($request, ['indexnow', 'gtm']);
        if ($data === []) {
            throw new UnprocessableEntityHttpException('Se requiere una configuración explícita.');
        }
        $indexNow = null;
        $gtm = null;
        if (array_key_exists('indexnow', $data)) {
            if (!$data['indexnow'] instanceof stdClass) {
                throw new UnprocessableEntityHttpException('IndexNow debe ser un objeto.');
            }
            $indexNow = self::fields($data['indexnow'], ['enabled']);
            if (array_keys($indexNow) !== ['enabled'] || !is_bool($indexNow['enabled'])) {
                throw new UnprocessableEntityHttpException('La activación de IndexNow debe ser explícita.');
            }
        }
        if (array_key_exists('gtm', $data)) {
            if (!$data['gtm'] instanceof stdClass) {
                throw new UnprocessableEntityHttpException('GTM debe ser un objeto.');
            }
            $gtm = self::fields($data['gtm'], ['container_id', 'enabled']);
            if ($gtm === [] || (array_key_exists('enabled', $gtm) && !is_bool($gtm['enabled']))) {
                throw new UnprocessableEntityHttpException('Configuración GTM inválida.');
            }
            if (array_key_exists('container_id', $gtm)
                && $gtm['container_id'] !== null
                && !is_string($gtm['container_id'])) {
                throw new UnprocessableEntityHttpException('Identificador GTM inválido.');
            }
            if (array_key_exists('container_id', $gtm) && ($gtm['enabled'] ?? false) === true) {
                throw new UnprocessableEntityHttpException('Configurar y activar GTM exige dos pasos separados.');
            }
        }

        try {
            $record = $this->settings->update(
                $tenant->id(),
                $branch->id(),
                'web',
                static function (array $previous) use ($indexNow, $gtm): array {
                    if ($indexNow !== null) {
                        $previous['indexnow_enabled'] = $indexNow['enabled'];
                    }
                    if ($gtm !== null) {
                        if (array_key_exists('container_id', $gtm)) {
                            $previous['gtm_container_id'] = $gtm['container_id'] === null
                                ? null
                                : TagManagerSettings::normalizeContainerId($gtm['container_id']);
                            // #867: changing the GTM ID always disables it.
                            $previous['gtm_enabled'] = false;
                        } elseif (array_key_exists('enabled', $gtm)) {
                            if ($gtm['enabled'] && $previous['gtm_container_id'] === null) {
                                throw new DomainException('Configura GTM antes de habilitarlo.');
                            }
                            $previous['gtm_enabled'] = $gtm['enabled'];
                        }
                    }

                    return $previous;
                },
            );
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        return $this->noStore(self::present($record));
    }

    /**
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    private static function fields(stdClass $value, array $allowed): array
    {
        $data = get_object_vars($value);
        if (array_diff(array_keys($data), $allowed) !== []) {
            throw new UnprocessableEntityHttpException('El cuerpo contiene campos no permitidos.');
        }

        return $data;
    }

    /** @param array{indexnow_enabled:bool,gtm_container_id:?string,gtm_enabled:bool} $record
     * @return array{indexnow:array{enabled:bool,last_notifications:array{}},gtm:array{container_id:?string,enabled:bool}}
     */
    private static function present(array $record): array
    {
        return [
            // Until a durable notification log is implemented, never fabricate a delivery.
            'indexnow' => ['enabled' => $record['indexnow_enabled'], 'last_notifications' => []],
            'gtm' => [
                'container_id' => $record['gtm_container_id'],
                'enabled' => $record['gtm_enabled'],
            ],
        ];
    }

    /** @param array<string,mixed> $data */
    private function noStore(array $data): JsonResponse
    {
        $response = $this->json($data);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }
}
