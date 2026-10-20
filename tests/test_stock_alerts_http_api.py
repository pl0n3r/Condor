"""Offline executable contract for #717, without production writes or credentials."""

import json
import subprocess
import tempfile
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



PHP_HTTP_SCENARIO = r'''<?php
declare(strict_types=1);
use App\Application\Notification\StockAlertService;
use App\Application\Notification\NotificationPreferenceService;
use App\Domain\Audit\Entity\AuditEvent;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventoryMovement;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Notification\Entity\NotificationDelivery;
use App\Domain\Notification\NotificationCatalog;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StockAlertsScopeSmokeTest extends WebTestCase
{
    public function testActualHttpPermissionsPersistenceAndCrossTenantIsolation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $suffix = strtolower(bin2hex(random_bytes(5)));

        $tenantA = new Tenant('Alertas A '.$suffix, 'alertas-a-'.$suffix);
        $legalA = new LegalEntity($tenantA, 'Legal A '.$suffix, null, true);
        $branchA = new Branch($tenantA, 'Sede A', 'sede-a-'.$suffix, $legalA, true);
        $sourceA = new InventorySource($tenantA, $legalA, 'Sede A', 'sede-a-'.$suffix, InventorySource::TYPE_BRANCH, $branchA);
        $productA = new Product($tenantA, 'Producto A', 'producto-a-'.$suffix);
        $variantA = new ProductVariant($tenantA, $productA, 'ALERT-A-'.$suffix, 'Tamaño');
        $owner = new User('stock-owner-'.$suffix.'@example.test', 'Owner A');
        $ownerMember = new Membership($tenantA, $owner, Membership::ROLE_OWNER);
        $viewer = new User('stock-viewer-'.$suffix.'@example.test', 'Viewer A');
        $viewerMember = new Membership($tenantA, $viewer, 'ADMIN');
        $viewRole = new Role($tenantA, 'Inventario lectura '.$suffix, ['inventory.view']);
        $viewAssignment = new BranchRoleAssignment($viewerMember, $branchA, $viewRole);

        $tenantB = new Tenant('Alertas B '.$suffix, 'alertas-b-'.$suffix);
        $legalB = new LegalEntity($tenantB, 'Legal B '.$suffix, null, true);
        $branchB = new Branch($tenantB, 'Sede B', 'sede-b-'.$suffix, $legalB, true);
        $sourceB = new InventorySource($tenantB, $legalB, 'Sede B', 'sede-b-'.$suffix, InventorySource::TYPE_BRANCH, $branchB);
        $productB = new Product($tenantB, 'Producto B', 'producto-b-'.$suffix);
        $variantB = new ProductVariant($tenantB, $productB, 'ALERT-B-'.$suffix, 'Tamaño B');
        $otherUser = new User('stock-other-'.$suffix.'@example.test', 'Owner B');
        $otherMember = new Membership($tenantB, $otherUser, Membership::ROLE_OWNER);
        foreach ([
            $tenantA, $legalA, $branchA, $sourceA, $productA, $variantA,
            $owner, $ownerMember, $viewer, $viewerMember, $viewRole, $viewAssignment,
            $tenantB, $legalB, $branchB, $sourceB, $productB, $variantB,
            $otherUser, $otherMember,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $url = '/api/v1/branches/'.$branchA->id().'/inventory/stock-alerts';
        $minimums = $url.'/minimums';
        $setUrl = $minimums.'/'.$variantA->id();
        $client->loginUser($owner);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $csrf = $crawler->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($csrf);
        self::assertNotSame('', $csrf);
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['alerts']);
        $client->request('GET', $minimums);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['minimums']);
        $client->request('GET', '/api/v1/branches/'.$branchB->id().'/inventory/stock-alerts');
        self::assertResponseStatusCodeSame(404);

        foreach ([null, -1, 2147483648, 1.5, '4'] as $invalid) {
            $client->jsonRequest('PUT', $setUrl, ['minimum' => $invalid], ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(422);
        }
        $client->jsonRequest('PUT', $setUrl, ['minimum' => 3]);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('PUT', $minimums.'/'.$variantB->id(), ['minimum' => 3], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest('PUT', $setUrl, ['minimum' => 3, 'tenant_id' => $tenantB->id()], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('PUT', $setUrl, ['minimum' => 3], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(200);
        $client->jsonRequest('PUT', $setUrl, ['minimum' => 3], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $em->getRepository(AuditEvent::class)->findBy([
            'tenant' => $tenantA, 'action' => 'inventory.stock_minimum.set',
        ]));
        $client->request('GET', $minimums);
        self::assertResponseStatusCodeSame(200);
        $rows = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['minimums'];
        self::assertSame([['variant_id' => $variantA->id(), 'source_id' => $sourceA->id(), 'minimum' => 3]], $rows);

        $movementA = new InventoryMovement(
            $tenantA, $sourceA, $variantA, InventoryMovement::TYPE_ADJUSTMENT_OUT, -4, 2, $owner->id(),
        );
        $alerts = StockAlertService::fromPreferences(static::getContainer()->get(NotificationPreferenceService::class));
        $delivery = $alerts->onMovement($ownerMember, $movementA, 3);
        self::assertNotNull($delivery);
        self::assertNull($alerts->onMovement($ownerMember, $movementA, 3));
        $em->persist($movementA);
        $em->persist($delivery);
        $em->flush();
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        $rows = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['alerts'];
        self::assertCount(1, $rows);
        self::assertSame($variantA->id(), $rows[0]['variant_id']);
        self::assertSame(3, $rows[0]['minimum']);
        self::assertSame(2, $rows[0]['quantity']);

        // Failed notifications from the same user are not active alerts.
        // Persist more than the API page limit to catch a filter-after-limit
        // regression; a valid pending stock.low alert must remain visible.
        usleep(1_100_000);
        for ($i = 0; $i < 205; ++$i) {
            $failed = new NotificationDelivery(
                $owner,
                NotificationCatalog::EVENT_STOCK_LOW,
                NotificationCatalog::CHANNEL_IN_APP,
                ['tenant_id' => $tenantA->id()],
            );
            $failed->markFailed('fixture');
            $em->persist($failed);
        }
        $em->flush();
        $client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        $rows = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['alerts'];
        self::assertCount(1, $rows);
        self::assertSame($variantA->id(), $rows[0]['variant_id']);

        $client->loginUser($viewer);
        $client->request('GET', $minimums);
        self::assertResponseStatusCodeSame(200);
        $client->jsonRequest('PUT', $setUrl, ['minimum' => 4], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/api/v1/branches/'.$branchB->id().'/inventory/stock-alerts/minimums');
        self::assertResponseStatusCodeSame(404);
    }
}
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
        self.assertIn("'status' => [", controller)

        # Minimums survive requests via tenant-scoped append-only records, not
        # process memory or client-supplied tenant IDs.
        self.assertIn("$em->persist(new AuditEvent(", controller)
        # A busy tenant can have many revisions for the same variant across
        # branches. A bounded read must reject overflow, not silently create
        # another audit record after losing the previous branch setting.
        self.assertIn("if (count($latest) > self::MAX_REVISIONS)", controller)
        self.assertEqual(2, controller.count("->setMaxResults(self::MAX_REVISIONS + 1)"))
        self.assertNotIn("->setMaxResults(200)\n                ->getQuery()->getArrayResult()", controller)
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

        # Exercise real HTTP auth/CSRF, persistence and isolation with fake tenants.
        with tempfile.TemporaryDirectory(prefix="condor-stock-alerts-http-") as tempdir:
            smoke = Path(tempdir) / "StockAlertsScopeSmokeTest.php"
            smoke.write_text(PHP_HTTP_SCENARIO, encoding="utf-8")
            result = subprocess.run(
                ["vendor/bin/simple-phpunit", str(smoke)],
                cwd=ROOT, text=True, capture_output=True, timeout=180, check=False,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
