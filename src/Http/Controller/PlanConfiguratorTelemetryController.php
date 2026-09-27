<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Commercial\CommercialCatalogReader;
use App\Domain\Observability\Entity\FunctionalSignal;
use App\Infrastructure\Observability\FunctionalSignalRecorder;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class PlanConfiguratorTelemetryController extends AbstractController
{
    /** @var list<string> */
    private const ALLOWED_FIELDS = [
        'event',
        'plan',
        'vertical',
        'cycle',
        'addon',
        'step',
    ];

    /** @var list<string> */
    private const EVENTS = [
        'start',
        'plan_selected',
        'plan_changed',
        'vertical',
        'addon',
        'abandonment',
        'completion',
        'proposal',
    ];

    /** @var list<string> */
    private const CYCLES = ['monthly', 'annual'];

    /** @var list<string> */
    private const STEPS = [
        'plan',
        'vertical',
        'scale',
        'addons',
        'cycle',
        'summary',
    ];

    public function __construct(
        private readonly CommercialCatalogReader $catalog,
        private readonly FunctionalSignalRecorder $signals,
        private readonly RateLimiterFactory $planConfiguratorEventsLimiter,
    ) {
    }

    #[Route(
        '/api/public/configurator/events',
        name: 'api_plan_configurator_events',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $limit = $this->planConfiguratorEventsLimiter
            ->create($request->getClientIp() ?? 'unknown')
            ->consume();

        if (!$limit->isAccepted()) {
            $retryAfter = max(
                1,
                $limit->getRetryAfter()->getTimestamp() - time(),
            );
            throw new TooManyRequestsHttpException(
                $retryAfter,
                'Demasiados eventos del configurador.',
            );
        }

        $context = $this->safeContext(self::payload($request));

        $this->signals->record(
            FunctionalSignal::CONFIGURATOR_FUNNEL,
            null,
            $context,
        );

        return $this->json(
            ['accepted' => true],
            Response::HTTP_ACCEPTED,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, scalar>
     */
    private function safeContext(array $payload): array
    {
        $catalog = $this->catalog->current(self::now());
        $planKeys = [];
        $verticalKeys = [];
        $addOnKeys = [];

        foreach ($catalog as $plan) {
            $planKey = $plan['key'] ?? null;
            if (is_string($planKey)) {
                $planKeys[] = $planKey;
            }

            $verticals = $plan['verticals'] ?? [];
            if (is_array($verticals)) {
                foreach ($verticals as $vertical) {
                    if (is_string($vertical)) {
                        $verticalKeys[] = $vertical;
                    }
                }
            }

            $addOns = $plan['addons'] ?? [];
            if (is_array($addOns)) {
                foreach ($addOns as $addOn) {
                    if (!is_array($addOn)) {
                        continue;
                    }
                    $addOnKey = $addOn['key'] ?? null;
                    if (is_string($addOnKey)) {
                        $addOnKeys[] = $addOnKey;
                    }
                }
            }
        }

        $event = self::allowedValue(
            $payload,
            'event',
            self::EVENTS,
            true,
        );
        if ($event === null) {
            throw new UnprocessableEntityHttpException(
                'El evento es obligatorio.',
            );
        }

        $context = ['event' => $event];

        foreach ([
            'plan' => array_values(array_unique($planKeys)),
            'vertical' => array_values(array_unique($verticalKeys)),
            'cycle' => self::CYCLES,
            'addon' => array_values(array_unique($addOnKeys)),
            'step' => self::STEPS,
        ] as $field => $allowed) {
            $value = self::allowedValue($payload, $field, $allowed);
            if ($value !== null) {
                $context[$field] = $value;
            }
        }

        return $context;
    }

    /** @return array<string, mixed> */
    private static function payload(Request $request): array
    {
        try {
            $decoded = json_decode(
                (string) $request->getContent(),
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new BadRequestHttpException('JSON inválido.', $exception);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new BadRequestHttpException(
                'El cuerpo JSON debe ser un objeto.',
            );
        }

        foreach (array_keys($decoded) as $key) {
            if (
                !is_string($key)
                || !in_array($key, self::ALLOWED_FIELDS, true)
            ) {
                throw new UnprocessableEntityHttpException(
                    'Campo no permitido en telemetría.',
                );
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $allowed
     */
    private static function allowedValue(
        array $payload,
        string $field,
        array $allowed,
        bool $required = false,
    ): ?string {
        $raw = $payload[$field] ?? null;
        if ($raw === null && !$required) {
            return null;
        }

        if (!is_string($raw)) {
            throw new UnprocessableEntityHttpException(
                sprintf('Valor inválido para %s.', $field),
            );
        }

        $value = trim($raw);
        if (!in_array($value, $allowed, true)) {
            throw new UnprocessableEntityHttpException(
                sprintf('Valor no permitido para %s.', $field),
            );
        }

        return $value;
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
