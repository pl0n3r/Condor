"""Offline executable contract for #717, without production writes or credentials."""

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/StockAlertController.php"
SUPPORT = ROOT / "src/Http/Controller/BranchApiSupport.php"
SERVICE = ROOT / "src/Application/Notification/StockAlertService.php"

PHP_CONTRACT = r'''
require 'vendor/autoload.php';
use App\Application\Notification\StockAlertService;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventoryMovement;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Symfony\Component\Routing\Attribute\Route;

$routes = [];
$reflection = new ReflectionClass(App\Http\Controller\StockAlertController::class);
foreach (['alerts','minimums','setMinimum'] as $method) {
    $attributes = $reflection->getMethod($method)->getAttributes(Route::class);
    if (count($attributes) !== 1) exit(11);
    $route = $attributes[0]->newInstance();
    $routes[$method] = [$route->getPath(), $route->getMethods()];
}
if ($routes['alerts'] !== ['/api/v1/branches/{branchId}/inventory/stock-alerts', ['GET']]
    || $routes['minimums'] !== ['/api/v1/branches/{branchId}/inventory/stock-alerts/minimums', ['GET']]
    || $routes['setMinimum'] !== ['/api/v1/branches/{branchId}/inventory/stock-alerts/minimums/{variantId}', ['PUT']]) exit(12);

function fixture(string $suffix): array {
    $tenant = new Tenant('Empresa '.$suffix, 'alerts-'.$suffix);
    $legal = new LegalEntity($tenant, 'Empresa '.$suffix, null, true);
    $source = new InventorySource($tenant, $legal, 'Sede', 'sede', InventorySource::TYPE_LOGICAL);
    $product = new Product($tenant, 'Producto', 'producto');
    $variant = new ProductVariant($tenant, $product, 'SKU-'.$suffix, 'Variante');
    $member = new Membership($tenant, new User('alert-'.$suffix.'@example.invalid', 'Usuario'), Membership::ROLE_OWNER);
    return [$tenant, $source, $variant, $member];
}
function movement(array $scope, int $delta, int $after): InventoryMovement {
    return new InventoryMovement($scope[0], $scope[1], $scope[2],
        InventoryMovement::TYPE_ADJUSTMENT_OUT, $delta, $after, null);
}
$a = fixture('a');
$b = fixture('b');
$service = new StockAlertService(static fn() => true);
$move = movement($a, -4, 2); // 6 -> 2, minimum 3
$first = $service->onMovement($a[3], $move, 3);
$repeat = $service->onMovement($a[3], $move, 3);
$belowAlready = $service->onMovement($a[3], movement($a, -1, 1), 3);
$foreign = $service->onMovement($b[3], movement($b, -4, 2), 3);
if ($first === null || $repeat !== null || $belowAlready !== null || $foreign === null) exit(13);
if (count($service->visibleInbox($a[3], [$first, $foreign])) !== 1
    || count($service->visibleInbox($b[3], [$first, $foreign])) !== 1) exit(14);
$crossTenantRejected = false;
try { $service->onMovement($b[3], movement($a, -4, 2), 3); }
catch (DomainException) { $crossTenantRejected = true; }
if (!$crossTenantRejected) exit(15);
print json_encode(['routes' => $routes, 'first' => $first->payload(),
    'single_delivery' => true, 'tenant_isolated' => true], JSON_THROW_ON_ERROR);
'''


class AlertasStockApiTests(unittest.TestCase):
    def test_acceptance(self):
        controller = CONTROLLER.read_text(encoding="utf-8")
        support = SUPPORT.read_text(encoding="utf-8")
        service = SERVICE.read_text(encoding="utf-8")

        # Both reads are scoped through server-side user/tenant/branch permission.
        self.assertIn("use BranchApiSupport;", controller)
        self.assertIn("$this->authorizedBranchScope($branchId, 'inventory.view')", controller)
        self.assertIn("$this->authorizedBranchScope($branchId, 'inventory.update')", controller)
        self.assertIn("$this->requireCsrf($request);", controller)
        self.assertIn("$this->payload($request, ['minimum'])", controller)
        self.assertIn("$this->authorization->require(", support)
        self.assertIn("'tenant' => $tenant", controller)
        self.assertIn("'branch' => $branch", controller)
        self.assertIn("'active' => true", controller)
        self.assertIn("'user' => $user", controller)
        self.assertIn("visibleInbox($membership, $candidateDeliveries)", controller)
        self.assertIn("NotificationDelivery::STATUS_PENDING", controller)
        self.assertIn("NotificationCatalog::CHANNEL_IN_APP", controller)

        # Minimums survive requests via tenant-scoped append-only records, not
        # process memory or client-supplied tenant IDs.
        self.assertIn("$em->persist(new AuditEvent(", controller)
        self.assertIn("'inventory.stock_minimum.set'", controller)
        self.assertIn("LockMode::PESSIMISTIC_WRITE", controller)
        self.assertIn("$this->entityManager->wrapInTransaction(", controller)
        self.assertIn("!is_int($data['minimum'])", controller)
        self.assertIn("!array_key_exists('minimum', $data)", controller)
        self.assertNotIn("$_SESSION", controller)
        self.assertNotIn("static $minimums", controller)
        self.assertIn("$this->seenMovements[$key] = true", service)

        # Execute real PHP domain logic and inspect Symfony route metadata.
        result = subprocess.run(
            ["php", "-r", PHP_CONTRACT], cwd=ROOT, text=True,
            capture_output=True, timeout=30, check=False,
        )
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        actual = json.loads(result.stdout)
        self.assertTrue(actual["single_delivery"])
        self.assertTrue(actual["tenant_isolated"])
        self.assertEqual(3, actual["first"]["minimum"])
        self.assertEqual(2, actual["first"]["quantity"])
        self.assertEqual(["PUT"], actual["routes"]["setMinimum"][1])


if __name__ == "__main__":
    unittest.main()
