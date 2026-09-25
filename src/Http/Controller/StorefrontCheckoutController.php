<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Application\Orders\OrderConflictException;
use App\Application\Orders\OrderService;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Orders\Entity\Order;
use App\Infrastructure\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class StorefrontCheckoutController extends AbstractController
{
    use OrderItemsApiSupport;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderService $orders,
        private readonly RateLimiterFactory $storefrontCheckoutLimiter,
    ) {
    }

    #[Route(
        '/checkout',
        name: 'app_storefront_checkout_domain',
        methods: ['POST'],
        priority: 100,
    )]
    #[Route(
        '/{tenant_slug}/checkout',
        name: 'app_storefront_checkout_platform',
        requirements: ['tenant_slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'],
        methods: ['POST'],
        priority: -90,
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->current();
        if ($tenant === null) {
            throw new NotFoundHttpException();
        }

        $key = $tenant->id().':'.($request->getClientIp() ?? 'unknown');
        $limit = $this->storefrontCheckoutLimiter->create($key)->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(
                1,
                $limit->getRetryAfter()->getTimestamp() - time(),
            );
            throw new TooManyRequestsHttpException(
                $retryAfter,
                'Demasiadas solicitudes de checkout. Intenta nuevamente más tarde.',
            );
        }

        $channel = $this->entityManager
            ->getRepository(SalesChannel::class)
            ->findOneBy([
                'tenant' => $tenant,
                'type' => SalesChannel::TYPE_ECOMMERCE,
                'active' => true,
            ]);
        if (
            !$channel instanceof SalesChannel
            || !$channel->isPublishable()
        ) {
            throw new NotFoundHttpException(
                'El canal e-commerce no está disponible.',
            );
        }

        $payload = $this->payload($request);
        $items = $this->orderItems(
            $this->entityManager,
            $tenant,
            $payload['items'] ?? null,
            'checkout',
        );
        $created = false;

        try {
            $order = $this->orders->create(
                $tenant,
                $channel->inventorySource(),
                $channel->priceList(),
                $channel,
                null,
                null,
                $this->requiredString($payload, 'idempotency_key'),
                $items,
                OrderService::ORIGIN_ECOMMERCE,
                static function () use (&$created): void {
                    $created = true;
                },
            );
        } catch (OrderConflictException $exception) {
            throw new ConflictHttpException(
                $exception->getMessage(),
                $exception,
            );
        } catch (DomainException $exception) {
            throw new UnprocessableEntityHttpException(
                $exception->getMessage(),
                $exception,
            );
        }

        return $this->json(
            ['order' => self::payloadOrder($order)],
            $created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        try {
            $decoded = json_decode(
                (string) $request->getContent(),
                false,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new BadRequestHttpException(
                'JSON inválido.',
                $exception,
            );
        }
        if (!is_object($decoded)) {
            throw new BadRequestHttpException(
                'El cuerpo debe ser un objeto JSON.',
            );
        }

        $payload = get_object_vars($decoded);
        if (
            array_diff(
                array_keys($payload),
                ['idempotency_key', 'items'],
            ) !== []
        ) {
            throw new UnprocessableEntityHttpException(
                'El checkout contiene campos no permitidos.',
            );
        }

        return $payload;
    }

    private function requiredString(
        array $payload,
        string $field,
    ): string {
        $value = $payload[$field] ?? null;
        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException(
                sprintf(
                    'El campo %s es obligatorio y debe ser texto.',
                    $field,
                ),
            );
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function payloadOrder(Order $order): array
    {
        return [
            'id' => $order->id(),
            'order_status' => $order->orderStatus(),
            'payment_status' => $order->paymentStatus(),
            'fulfillment_status' => $order->fulfillmentStatus(),
            'currency' => $order->currency(),
            'total_amount_minor' => $order->totalAmountMinor(),
            'created_at' => $order->createdAt()->format(DATE_ATOM),
            'expires_at' => $order->expiresAt()?->format(DATE_ATOM),
        ];
    }
}
