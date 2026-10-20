#!/usr/bin/env python3
"""Checkout HTTP negatives (#731): execute the actual controller and item trait.

All tenants, products, channels, requests and prices are synthetic. The PHP
fixture provides only infrastructure doubles; it imports the production
StorefrontCheckoutController and OrderItemsApiSupport unchanged.
"""

from __future__ import annotations

import json
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/StorefrontCheckoutController.php"
ITEM_SUPPORT = ROOT / "src/Http/Controller/OrderItemsApiSupport.php"

FIXTURE = r'''<?php
declare(strict_types=1);

namespace Symfony\Component\Routing\Attribute {
    #[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
    final class Route {
        public function __construct(...$arguments) {}
    }
}
namespace Symfony\Component\HttpKernel\Exception {
    class NotFoundHttpException extends \RuntimeException {}
    class BadRequestHttpException extends \RuntimeException {}
    class UnprocessableEntityHttpException extends \RuntimeException {}
    class ConflictHttpException extends \RuntimeException {}
    class TooManyRequestsHttpException extends \RuntimeException {
        public function __construct(int $retryAfter, string $message = '') {
            parent::__construct($message);
        }
    }
}
namespace Symfony\Component\HttpFoundation {
    class Response {
        public const HTTP_CREATED = 201;
        public const HTTP_OK = 200;
    }
    class Request {
        public function __construct(private string $json, private ?string $ip = '127.0.0.1') {}
        public function getContent(): string { return $this->json; }
        public function getClientIp(): ?string { return $this->ip; }
    }
    class JsonResponse {
        public function __construct(public array $data, public int $status = 200) {}
    }
}
namespace Symfony\Bundle\FrameworkBundle\Controller {
    abstract class AbstractController {
        protected function json(array $data, int $status = 200): \Symfony\Component\HttpFoundation\JsonResponse {
            return new \Symfony\Component\HttpFoundation\JsonResponse($data, $status);
        }
    }
}
namespace Symfony\Component\RateLimiter {
    class Limit {
        public function isAccepted(): bool { return true; }
    }
    class Limiter {
        public function consume(): Limit { return new Limit(); }
    }
    class RateLimiterFactory {
        public function create(string $key): Limiter { return new Limiter(); }
    }
}
namespace App\Domain\Organization\Entity {
    class Tenant {
        public function __construct(private string $identifier) {}
        public function id(): string { return $this->identifier; }
    }
}
namespace App\Infrastructure\Tenancy {
    class TenantContext {
        public function __construct(private ?\App\Domain\Organization\Entity\Tenant $tenant) {}
        public function current(): ?\App\Domain\Organization\Entity\Tenant { return $this->tenant; }
    }
}
namespace App\Domain\Catalog\Entity {
    class Product {
        public function isActive(): bool { return true; }
    }
    class ProductVariant {
        public function __construct(
            public string $identifier,
            public \App\Domain\Organization\Entity\Tenant $tenant,
            public int $serverPriceMinor,
        ) {}
        public function product(): Product { return new Product(); }
    }
}
namespace App\Domain\Commerce\Entity {
    class PriceList {}
    class SalesChannel {
        public const TYPE_ECOMMERCE = 'ecommerce';
        public function __construct(
            public \App\Domain\Organization\Entity\Tenant $tenant,
            private bool $publishable = true,
        ) {}
        public function isPublishable(): bool { return $this->publishable; }
        public function inventorySource(): \App\Domain\Inventory\Entity\InventorySource {
            return new \App\Domain\Inventory\Entity\InventorySource();
        }
        public function priceList(): PriceList { return new PriceList(); }
    }
}
namespace App\Domain\Inventory\Entity { class InventorySource {} }
namespace App\Domain\Orders\Entity {
    class Order {
        public function __construct(private int $serverTotalMinor) {}
        public function id(): string { return 'order-synthetic'; }
        public function orderStatus(): string { return 'pending'; }
        public function paymentStatus(): string { return 'unpaid'; }
        public function fulfillmentStatus(): string { return 'reserved'; }
        public function currency(): string { return 'COP'; }
        public function totalAmountMinor(): int { return $this->serverTotalMinor; }
        public function createdAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-10-20T00:00:00Z'); }
        public function expiresAt(): ?\DateTimeImmutable { return null; }
    }
}
namespace App\Application\Orders {
    class OrderConflictException extends \RuntimeException {}
    class OrderService {
        public const ORIGIN_ECOMMERCE = 'ecommerce';
        public int $calls = 0;
        public function create(
            \App\Domain\Organization\Entity\Tenant $tenant,
            \App\Domain\Inventory\Entity\InventorySource $source,
            \App\Domain\Commerce\Entity\PriceList $priceList,
            ?\App\Domain\Commerce\Entity\SalesChannel $channel,
            mixed $customer,
            ?string $actorUserId,
            string $idempotencyKey,
            array $items,
            string $origin,
            ?callable $onCreated = null,
        ): \App\Domain\Orders\Entity\Order {
            $this->calls++;
            if ($origin !== self::ORIGIN_ECOMMERCE || $channel?->tenant !== $tenant) {
                throw new \RuntimeException('Cross-tenant channel or wrong origin.');
            }
            $total = 0;
            foreach ($items as $item) {
                if ($item['variant']->tenant !== $tenant) {
                    throw new \RuntimeException('A foreign variant reached order creation.');
                }
                $total += $item['variant']->serverPriceMinor * $item['quantity'];
            }
            $onCreated && $onCreated();
            return new \App\Domain\Orders\Entity\Order($total);
        }
    }
}
namespace Doctrine\ORM {
    interface EntityManagerInterface {
        public function getRepository(string $class): object;
    }
}
namespace CheckoutAcceptance {
    use App\Domain\Organization\Entity\Tenant;
    use App\Domain\Catalog\Entity\ProductVariant;
    use App\Domain\Commerce\Entity\SalesChannel;
    use App\Application\Orders\OrderService;
    use App\Http\Controller\StorefrontCheckoutController;
    use App\Infrastructure\Tenancy\TenantContext;
    use Doctrine\ORM\EntityManagerInterface;
    use Symfony\Component\RateLimiter\RateLimiterFactory;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
    use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

    final class FakeRepository {
        public function __construct(private array $rows) {}
        public function findOneBy(array $criteria): ?object {
            foreach ($this->rows as $row) {
                $match = true;
                foreach ($criteria as $key => $value) {
                    if ($key === 'id') { $candidate = $row->identifier; }
                    elseif ($key === 'active') { $candidate = true; }
                    elseif ($key === 'type') { $candidate = SalesChannel::TYPE_ECOMMERCE; }
                    else { $candidate = $row->$key; }
                    if ($candidate !== $value) { $match = false; break; }
                }
                if ($match) { return $row; }
            }
            return null;
        }
    }
    final class FakeManager implements EntityManagerInterface {
        public function __construct(private array $repos) {}
        public function getRepository(string $class): object { return $this->repos[$class]; }
    }

    require_once __ITEMS__;
    require_once __CONTROLLER__;

    function expectReject(callable $run, string $type, string $name): void {
        try { $run(); }
        catch (\Throwable $error) {
            if ($error instanceof $type) { return; }
            throw new \RuntimeException($name.': wrong rejection type '.get_class($error), 0, $error);
        }
        throw new \RuntimeException($name.': payload was accepted.');
    }

    $tenantA = new Tenant('tenant-a');
    $tenantB = new Tenant('tenant-b');
    $variantA = new ProductVariant('variant-a', $tenantA, 2800);
    $variantB = new ProductVariant('variant-b', $tenantB, 999999);
    $channelA = new SalesChannel($tenantA);
    $channelB = new SalesChannel($tenantB);
    $manager = new FakeManager([
        SalesChannel::class => new FakeRepository([$channelA, $channelB]),
        ProductVariant::class => new FakeRepository([$variantA, $variantB]),
    ]);
    $orders = new OrderService();
    $controllerA = new StorefrontCheckoutController(
        new TenantContext($tenantA), $manager, $orders, new RateLimiterFactory(),
    );
    $controllerB = new StorefrontCheckoutController(
        new TenantContext($tenantB), $manager, $orders, new RateLimiterFactory(),
    );
    $makeRequest = static fn(array $data): Request =>
        new Request(json_encode($data, JSON_THROW_ON_ERROR));
    $key = 'checkout-synthetic-1';

    expectReject(
        fn() => $controllerA($makeRequest(['idempotency_key' => $key, 'items' => []])),
        UnprocessableEntityHttpException::class, 'empty_cart',
    );
    expectReject(
        fn() => $controllerA($makeRequest([
            'idempotency_key' => $key,
            'items' => [['variant_id' => 'variant-b', 'quantity' => 1]],
        ])),
        NotFoundHttpException::class, 'other_tenant_variant',
    );
    expectReject(
        fn() => $controllerA($makeRequest([
            'idempotency_key' => $key,
            'items' => [['variant_id' => 'variant-a', 'quantity' => 2]],
            'total_amount_minor' => 1,
        ])),
        UnprocessableEntityHttpException::class, 'manipulated_total',
    );
    expectReject(
        fn() => $controllerA($makeRequest([
            'idempotency_key' => $key,
            'items' => [['variant_id' => 'variant-a', 'quantity' => 2, 'unit_price_minor' => 1]],
        ])),
        UnprocessableEntityHttpException::class, 'manipulated_unit_price',
    );
    if ($orders->calls !== 0) {
        throw new \RuntimeException('A rejected request created an order.');
    }

    $valid = $controllerA($makeRequest([
        'idempotency_key' => $key,
        'items' => [['variant_id' => 'variant-a', 'quantity' => 2]],
    ]));
    if ($valid->status !== 201 || $valid->data['order']['total_amount_minor'] !== 5600
        || $valid->data['order']['currency'] !== 'COP' || $orders->calls !== 1) {
        throw new \RuntimeException('Checkout total was not derived from server-side pricing.');
    }
    $foreign = $controllerB($makeRequest([
        'idempotency_key' => 'tenant-b-checkout',
        'items' => [['variant_id' => 'variant-b', 'quantity' => 1]],
    ]));
    if ($foreign->status !== 201 || $foreign->data['order']['total_amount_minor'] !== 999999
        || $orders->calls !== 2) {
        throw new \RuntimeException('Tenant B failed its separate checkout contract.');
    }

    echo json_encode([
        'empty_cart' => 422,
        'foreign_variant' => 404,
        'client_total' => 422,
        'client_unit_price' => 422,
        'tenant_a_server_total' => 5600,
        'tenant_b_server_total' => 999999,
        'valid_status' => 201,
    ], JSON_THROW_ON_ERROR);
}
'''


class CheckoutNegativasHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        self.assertTrue(CONTROLLER.is_file())
        self.assertTrue(ITEM_SUPPORT.is_file())
        self.assertIsNotNone(shutil.which("php"), "PHP CLI is required")
        with tempfile.TemporaryDirectory() as temporary:
            fixture = Path(temporary) / "checkout-negatives.php"
            fixture.write_text(
                FIXTURE.replace("__ITEMS__", json.dumps(str(ITEM_SUPPORT)))
                .replace("__CONTROLLER__", json.dumps(str(CONTROLLER))),
                encoding="utf-8",
            )
            completed = subprocess.run(
                ["php", str(fixture)],
                text=True,
                capture_output=True,
                check=False,
                timeout=40,
            )
        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertEqual(
            json.loads(completed.stdout),
            {
                "empty_cart": 422,
                "foreign_variant": 404,
                "client_total": 422,
                "client_unit_price": 422,
                "tenant_a_server_total": 5600,
                "tenant_b_server_total": 999999,
                "valid_status": 201,
            },
        )


if __name__ == "__main__":
    unittest.main()
