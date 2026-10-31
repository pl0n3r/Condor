<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Commercial\CommercialCatalogReader;
use App\Domain\Audit\Entity\PlatformAuditEvent;
use App\Domain\Commercial\Entity\Capability;
use App\Domain\Commercial\Entity\Plan;
use App\Domain\Commercial\Entity\PlanVersion;
use App\Domain\Commercial\PlanVersionTimeline;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** Owner-only plan configuration; does not establish prices or charge anyone. */
final class PlatformPlanCatalogController extends PlatformOwnerApiController
{
    private const CSRF_ID = 'platform_plan_catalog_management';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PlanVersionTimeline $timeline,
        private readonly CommercialCatalogReader $catalog,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/adminpl0n3r/planes', name: 'platform_plan_catalog_panel', methods: ['GET'])]
    public function index(): Response
    {
        $this->platformOwner();
        $plans = $this->catalog->current(self::now());
        $token = (string) $this->csrf->getToken(self::CSRF_ID)->getValue();

        $html = '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Catálogo de planes — Condor</title></head><body>'
            . '<main><h1>Catálogo de planes</h1>'
            . '<p>Los precios no se definen aquí. Solo el dueño decide tarifas y cobros en live.</p>';

        if ($plans === []) {
            $html .= '<p>No hay planes vigentes para editar.</p>';
        }
        foreach ($plans as $plan) {
            $key = self::escape($plan['key']);
            $name = self::escape($plan['name']);
            $limits = self::escape((string) json_encode($plan['limits'], JSON_THROW_ON_ERROR));
            $capabilities = self::escape(implode(', ', $plan['capabilities']));
            $action = '/adminpl0n3r/planes/' . rawurlencode($plan['key']);
            $html .= '<section><h2>' . $name . '</h2>'
                . '<p>Tarifa: sin definir para nuevas versiones. Versión ' . (int) $plan['version'] . '.</p>'
                . '<form method="post" action="' . self::escape($action) . '">'
                . '<input type="hidden" name="_csrf_token" value="' . self::escape($token) . '">'
                . '<input type="hidden" name="expected_version" value="' . (int) $plan['version'] . '">'
                . '<label>Nombre <input name="name" maxlength="160" required value="' . $name . '"></label>'
                . '<label>Límites (objeto JSON) <textarea name="limits" required>' . $limits . '</textarea></label>'
                . '<label>Funcionalidades (claves separadas por coma)'
                . '<input name="capabilities" value="' . $capabilities . '"></label>'
                . '<button type="submit">Guardar cambios de ' . $key . '</button></form></section>';
        }
        return new Response($html . '</main></body></html>', Response::HTTP_OK);
    }

    #[Route('/adminpl0n3r/planes/{key}', name: 'platform_plan_catalog_form_update', methods: ['POST'])]
    public function saveForm(string $key, Request $request): Response
    {
        $owner = $this->platformOwner();
        $allowedFields = ['_csrf_token', 'capabilities', 'expected_version', 'limits', 'name'];
        foreach (array_keys($request->request->all()) as $field) {
            if (!in_array($field, $allowedFields, true)) {
                throw new UnprocessableEntityHttpException('Campo de plan no permitido.');
            }
        }
        $token = (string) $request->request->get('_csrf_token', '');
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $token)) {
            throw new AccessDeniedHttpException('CSRF inválido.');
        }
        try {
            $limits = json_decode(
                (string) $request->request->get('limits', ''), true, 512, JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new UnprocessableEntityHttpException('Límites JSON inválidos.', $exception);
        }
        $rawCapabilities = (string) $request->request->get('capabilities', '');
        $payload = [
            'name' => $request->request->get('name'),
            'limits' => $limits,
            'capabilities' => $rawCapabilities === ''
                ? [] : array_map('trim', explode(',', $rawCapabilities)),
            'expected_version' => filter_var(
                $request->request->get('expected_version'), FILTER_VALIDATE_INT,
            ),
        ];
        try {
            $this->apply($key, $payload, $owner->id());
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
        return $this->redirectToRoute('platform_plan_catalog_panel');
    }

    #[Route('/adminpl0n3r/api/plans/{key}', name: 'platform_plan_catalog_api_update', methods: ['POST'])]
    public function saveApi(string $key, Request $request): JsonResponse
    {
        $owner = $this->platformOwner();
        $this->requireManagementCsrf($request, self::CSRF_ID);
        try {
            $result = $this->apply($key, $this->jsonPayload($request), $owner->id());
            return $this->json($result);
        } catch (DomainException $exception) {
            return $this->json(
                ['error' => $exception->getMessage()], JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function apply(string $key, array $payload, string $actor): array
    {
        $fields = array_keys($payload);
        sort($fields);
        if ($fields !== ['capabilities', 'expected_version', 'limits', 'name']
            || !is_string($payload['name'])
            || !is_int($payload['expected_version'])
            || $payload['expected_version'] < 1
            || !is_array($payload['limits'])
            || !is_array($payload['capabilities'])
            || !array_is_list($payload['capabilities'])
            || count($payload['capabilities']) > 50
        ) {
            throw new DomainException('Configuración de plan inválida.');
        }
        foreach ($payload['capabilities'] as $cap) {
            if (!is_string($cap) || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $cap) !== 1) {
                throw new DomainException('Funcionalidad inválida.');
            }
        }
        if (count(array_unique($payload['capabilities'])) !== count($payload['capabilities'])) {
            throw new DomainException('Funcionalidad duplicada.');
        }

        $now = self::now();
        return $this->em->wrapInTransaction(function () use ($key, $payload, $actor, $now): array {
            $plan = $this->em->getRepository(Plan::class)
                ->findOneBy(['key' => $key, 'active' => true]);
            if (!$plan instanceof Plan) {
                throw new DomainException('Plan inexistente.');
            }
            $this->em->lock($plan, LockMode::PESSIMISTIC_WRITE);
            $versions = $this->em->getRepository(PlanVersion::class)->findBy(['plan' => $plan]);
            $current = $this->timeline->effectiveAt($versions, $plan, $now);
            if (!$current instanceof PlanVersion || $current->version() !== $payload['expected_version']) {
                throw new DomainException('La versión del plan cambió: recarga antes de guardar.');
            }

            $chosen = [];
            foreach ($payload['capabilities'] as $capKey) {
                $cap = $this->em->getRepository(Capability::class)
                    ->findOneBy(['key' => $capKey, 'active' => true]);
                if (!$cap instanceof Capability) {
                    throw new DomainException('Funcionalidad del catálogo desconocida.');
                }
                $chosen[] = $cap;
            }

            $before = self::snapshot($plan, $current);
            $next = new PlanVersion(
                $plan, $current->version() + 1, null, null, true, $payload['limits'], $now,
            );
            foreach ($current->verticals() as $vertical) {
                $next->addVertical($vertical);
            }
            foreach ($current->addOns() as $addOn) {
                $next->addAddOn($addOn);
            }
            foreach ($chosen as $cap) {
                $next->addCapability($cap);
            }

            $plan->rename($payload['name']);
            $current->closeAt($now);
            $this->timeline->assertCanAdd($versions, $next);
            $after = self::snapshot($plan, $next);
            $this->em->persist($next);
            $this->em->persist(new PlatformAuditEvent(
                $actor, null, 'commercial.plan.updated', 'commercial_plan', $plan->id(),
                ['old' => $before, 'new' => $after],
            ));
            return $after;
        });
    }

    /** @return array<string, mixed> */
    private static function snapshot(Plan $plan, PlanVersion $version): array
    {
        return [
            'plan_key' => $plan->key(),
            'name' => $plan->name(),
            'version' => $version->version(),
            'limits' => $version->limits(),
            'capabilities' => array_map(
                static fn (Capability $cap): string => $cap->key(), $version->capabilities(),
            ),
            'monthly_amount' => $version->monthlyAmount(),
            'annual_amount' => $version->annualAmount(),
            'quote_required' => $version->quoteRequired(),
        ];
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
