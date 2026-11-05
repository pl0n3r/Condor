<?php

declare(strict_types=1);

// Worker de prueba (#1486): un proceso PHP real con su propia conexión MariaDB
// que crea una orden mediante el coordinador. Salida: una línea JSON.

use App\Application\Commercial\EntitlementSnapshot;
use App\Application\Commercial\ExecutionContext;
use App\Application\Commercial\OrderCreationCoordinator;
use App\Application\Commercial\UsageLimitExceeded;
use App\Application\Orders\OrderConflictException;
use App\Application\Orders\OrderService;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\Entity\PriceList;
use App\Domain\Commerce\Entity\SalesChannel;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;

require dirname(__DIR__, 5).'/config/bootstrap.php';

/** @var array<string, mixed> $cfg */
$cfg = json_decode((string) ($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);

$kernel = new App\Kernel('test', false);
$kernel->boot();
$services = $kernel->getContainer()->get('test.service_container');
$em = $services->get(EntityManagerInterface::class);
$coordinator = $services->get(OrderCreationCoordinator::class);

touch((string) $cfg['ready']);
$deadline = microtime(true) + 30;
while (!is_file((string) $cfg['go']) && microtime(true) < $deadline) {
    usleep(1000);
}

$tenant = $em->find(Tenant::class, $cfg['tenant']);
$variant = $em->find(ProductVariant::class, $cfg['variant']);
$context = new ExecutionContext(
    $tenant->id(),
    new EntitlementSnapshot(
        $tenant->id(), 'test-plan', 1, 'general', [], [],
        ['companies' => 1, 'users' => 5, 'locations' => 5, 'orders' => $cfg['limit']],
        [], new DateTimeImmutable('now'),
    ),
    'worker',
);

try {
    $order = $coordinator->createWithin(
        $context,
        $tenant,
        $em->find(InventorySource::class, $cfg['source']),
        $em->find(PriceList::class, $cfg['list']),
        $em->find(SalesChannel::class, $cfg['channel']),
        null,
        null,
        $cfg['key'],
        [['variant' => $variant, 'quantity' => 1]],
        OrderService::ORIGIN_ECOMMERCE,
        static function () use (&$created): void {
            $created = true;
        },
    );
    echo json_encode(['status' => ($created ?? false) ? 'created' : 'replayed', 'order' => $order->id()]), "\n";
} catch (UsageLimitExceeded) {
    echo json_encode(['status' => 'limit', 'order' => null]), "\n";
} catch (OrderConflictException) {
    echo json_encode(['status' => 'conflict', 'order' => null]), "\n";
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'order' => null, 'message' => $e->getMessage()]), "\n";
}
