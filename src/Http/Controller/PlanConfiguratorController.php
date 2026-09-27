<?php
declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Commercial\CommercialCatalogReader;
use App\Application\Commercial\PlanConfiguratorCatalogReader;
use App\Application\Commercial\PlanQuoteService;
use App\Domain\Commercial\Entity\Vertical;
use App\Shared\Version\AppVersion;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
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

final class PlanConfiguratorController extends AbstractController
{
    public function __construct(
        private readonly CommercialCatalogReader $catalog,
        private readonly PlanConfiguratorCatalogReader $configurator,
        private readonly PlanQuoteService $quotes,
        private readonly EntityManagerInterface $entityManager,
        private readonly RateLimiterFactory $planConfiguratorLimiter,
    ) {}

    #[Route('/configurar-condor', name: 'app_plan_configurator', methods: ['GET'])]
    public function page(AppVersion $version): Response
    {
        return $this->render('configurator/index.html.twig', [
            'app_version' => $version->human(),
        ]);
    }

    #[Route('/api/public/configurator/catalog', name: 'api_plan_configurator_catalog', methods: ['GET'])]
    public function catalog(): JsonResponse
    {
        $at = self::now();
        $plans = array_map(
            static fn (array $plan): array => [
                'key' => $plan['key'],
                'name' => $plan['name'],
                'version' => $plan['version'],
                'monthly_amount' => $plan['monthly_amount'],
                'annual_amount' => $plan['annual_amount'],
                'quote_required' => $plan['quote_required'],
                'verticals' => $plan['verticals'],
            ],
            $this->catalog->current($at),
        );
        $verticals = $this->entityManager
            ->getRepository(Vertical::class)
            ->findBy(['active' => true], ['name' => 'ASC']);

        return $this->json([
            'plans' => $plans,
            'verticals' => array_map(
                static fn (Vertical $vertical): array => [
                    'key' => $vertical->key(),
                    'name' => $vertical->name(),
                ],
                $verticals,
            ),
        ]);
    }

    #[Route('/api/public/configurator/options', name: 'api_plan_configurator_options', methods: ['GET'])]
    public function options(Request $request): JsonResponse
    {
        try {
            return $this->json($this->configurator->options(
                self::queryString($request, 'plan'),
                self::queryString($request, 'vertical'),
                self::now(),
            ));
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException(
                $exception->getMessage(),
                $exception,
            );
        }
    }

    #[Route('/api/public/configurator/quote', name: 'api_plan_configurator_quote', methods: ['POST'])]
    public function quote(Request $request): JsonResponse
    {
        $limit = $this->planConfiguratorLimiter
            ->create($request->getClientIp() ?? 'unknown')
            ->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(
                1,
                $limit->getRetryAfter()->getTimestamp() - time(),
            );
            throw new TooManyRequestsHttpException(
                $retryAfter,
                'Demasiadas solicitudes al configurador.',
            );
        }

        $payload = self::payload($request);
        try {
            $quote = $this->quotes->preview(
                self::requiredString($payload, 'plan'),
                self::requiredString($payload, 'vertical'),
                self::requiredString($payload, 'cycle'),
                self::quantities($payload),
                self::addOns($payload),
                self::now(),
                is_int($payload['total'] ?? null)
                    ? $payload['total']
                    : null,
            );
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException(
                $exception->getMessage(),
                $exception,
            );
        }

        return $this->json([
            'quote' => [
                'cycle' => $quote->cycle(),
                'quantities' => $quote->quantities(),
                'addons' => $quote->addOns(),
                'base_amount' => $quote->baseAmount(),
                'addon_amount' => $quote->addOnAmount(),
                'total_amount' => $quote->totalAmount(),
                'proposal_required' => $quote->proposalRequired(),
                'valid_until' => $quote->validUntil()->format(DATE_ATOM),
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private static function payload(Request $request): array
    {
        try {
            $payload = $request->toArray();
        } catch (JsonException $exception) {
            throw new BadRequestHttpException('JSON inválido.', $exception);
        }
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, [
                'plan', 'vertical', 'cycle', 'quantities', 'addons', 'total',
            ], true)) {
                throw new UnprocessableEntityHttpException(
                    'Campo no permitido en configurador.',
                );
            }
        }
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private static function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new UnprocessableEntityHttpException(
                sprintf('El campo %s es obligatorio.', $key),
            );
        }
        return trim($value);
    }

    /** @param array<string,mixed> $payload @return array<string,int> */
    private static function quantities(array $payload): array
    {
        $input = $payload['quantities'] ?? [];
        if (!is_array($input)) {
            throw new UnprocessableEntityHttpException('Cantidades inválidas.');
        }
        $result = [];
        foreach ($input as $key => $value) {
            if (!is_string($key) || !is_int($value)) {
                throw new UnprocessableEntityHttpException('Cantidades inválidas.');
            }
            $result[$key] = $value;
        }
        return $result;
    }

    /** @param array<string,mixed> $payload @return list<string> */
    private static function addOns(array $payload): array
    {
        $input = $payload['addons'] ?? [];
        if (!is_array($input) || !array_is_list($input)) {
            throw new UnprocessableEntityHttpException('Add-ons inválidos.');
        }
        foreach ($input as $value) {
            if (!is_string($value)) {
                throw new UnprocessableEntityHttpException('Add-ons inválidos.');
            }
        }
        return array_values($input);
    }

    private static function queryString(Request $request, string $key): string
    {
        $value = trim((string) $request->query->get($key, ''));
        if ($value === '') {
            throw new UnprocessableEntityHttpException(
                sprintf('El parámetro %s es obligatorio.', $key),
            );
        }
        return $value;
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
