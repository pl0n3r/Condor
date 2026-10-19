#!/usr/bin/env python3
"""AC-01 #685: enforce branch/tenant scoping and idempotent purchasing HTTP API."""

from __future__ import annotations

import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

PHP_PURCHASING_SCOPE_SMOKE = r'''<?php
declare(strict_types=1);

use App\Domain\Identity\Entity\BranchRoleAssignment;
use App\Domain\Identity\Entity\Membership;
use App\Domain\Identity\Entity\Role;
use App\Domain\Identity\Entity\User;
use App\Domain\Inventory\Entity\InventorySource;
use App\Domain\Organization\Entity\Branch;
use App\Domain\Organization\Entity\LegalEntity;
use App\Domain\Organization\Entity\Tenant;
use App\Domain\Production\Entity\Material;
use App\Domain\Production\ValueObject\UnitOfMeasure;
use App\Domain\Purchasing\Entity\PurchaseOrder;
use App\Domain\Purchasing\Entity\Supplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PurchasingScopeSmokeTest extends WebTestCase
{
    public function testAuthenticatedTenantAndBranchIsolation(): void
    {
        $client = static::createClient();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = strtolower(bin2hex(random_bytes(5)));

        $tenantA = new Tenant('Compras A '.$suffix, 'compras-a-'.$suffix);
        $legalA = new LegalEntity($tenantA, 'Compras A SAS '.$suffix, null, true);
        $branchA = new Branch($tenantA, 'Principal', 'principal-'.$suffix, $legalA, true);
        $branchHidden = new Branch($tenantA, 'Restringida', 'restringida-'.$suffix, $legalA);
        $source = new InventorySource(
            $tenantA, $legalA, 'Principal', 'principal-'.$suffix,
            InventorySource::TYPE_BRANCH, $branchA,
        );

        $tenantB = new Tenant('Compras B '.$suffix, 'compras-b-'.$suffix);
        $legalB = new LegalEntity($tenantB, 'Compras B SAS '.$suffix, null, true);
        $branchB = new Branch($tenantB, 'Ajena', 'ajena-'.$suffix, $legalB, true);
        $supplierB = new Supplier($tenantB, 'FOREIGN-'.$suffix, 'Proveedor de otra empresa');

        $owner = new User('purchasing-owner-'.$suffix.'@example.test', 'Owner');
        $ownerMember = new Membership($tenantA, $owner, Membership::ROLE_OWNER);
        $viewer = new User('purchasing-viewer-'.$suffix.'@example.test', 'Viewer');
        $viewerMember = new Membership($tenantA, $viewer, 'ADMIN');
        $role = new Role($tenantA, 'Sólo lectura '.$suffix, ['inventory.view']);
        $assignment = new BranchRoleAssignment($viewerMember, $branchA, $role);

        foreach ([
            $tenantA, $legalA, $branchA, $branchHidden, $source,
            $tenantB, $legalB, $branchB, $supplierB,
            $owner, $ownerMember, $viewer, $viewerMember, $role, $assignment,
        ] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $suppliersUrl = '/api/v1/branches/'.$branchA->id().'/purchasing/suppliers';
        $ordersUrl = '/api/v1/branches/'.$branchA->id().'/purchasing/orders';
        $client->loginUser($owner);
        $crawler = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $csrf = $crawler->filter('#condor-admin-root')->attr('data-access-token');
        self::assertIsString($csrf);
        self::assertNotSame('', $csrf);

        // Authorized path and tenant B must be isolated through real HTTP responses.
        $client->request('GET', $suppliersUrl);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['suppliers']);
        $client->request('GET', '/api/v1/branches/'.$branchB->id().'/purchasing/suppliers');
        self::assertResponseStatusCodeSame(404);

        // Explicit null must be rejected, while an omitted payment term defaults to zero.
        $client->jsonRequest('POST', $suppliersUrl, [
            'code' => 'NULL-'.$suffix, 'name' => 'Inválido', 'payment_terms_days' => null,
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(422);

        $client->jsonRequest('POST', $suppliersUrl, [
            'code' => 'LOCAL-'.$suffix, 'name' => 'Proveedor propio',
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
        $supplierAId = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['supplier']['id'];
        $supplierA = $manager->getRepository(Supplier::class)->find($supplierAId);
        self::assertInstanceOf(Supplier::class, $supplierA);
        self::assertSame(0, $supplierA->paymentTermsDays());

        // A foreign supplier ID cannot be used to create a local purchase.
        $client->jsonRequest('POST', $ordersUrl, [
            'supplier_id' => $supplierB->id(), 'lines' => [],
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);

        // A delegated viewer sees only assigned branches and cannot mutate.
        $client->loginUser($viewer);
        $client->request('GET', $suppliersUrl);
        self::assertResponseStatusCodeSame(200);
        $client->request('GET', '/api/v1/branches/'.$branchHidden->id().'/purchasing/suppliers');
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', $suppliersUrl, [
            'code' => 'DENIED-'.$suffix, 'name' => 'No autorizado',
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(403);

        // History is readable and the same receipt can be retried after source deactivation.
        $material = new Material($tenantA, 'MAT-'.$suffix, 'Tela', UnitOfMeasure::from('m'));
        $order = new PurchaseOrder($tenantA, $legalA, $supplierA);
        $order->addLine($material, '1', '2');
        $order->markReceived('receipt-'.$suffix, $source->id());
        $manager->persist($material);
        $manager->persist($order);
        $manager->flush();
        $source->deactivate();
        $manager->flush();

        $client->loginUser($owner);
        $client->request('GET', $ordersUrl);
        self::assertResponseStatusCodeSame(200);
        $orders = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['orders'];
        self::assertContains($order->id(), array_column($orders, 'id'));

        $client->jsonRequest('POST', $ordersUrl.'/'.$order->id().'/receive', [
            'idempotency_key' => 'receipt-'.$suffix,
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(200);

        // New orders are still forbidden when the branch inventory source is inactive.
        $client->jsonRequest('POST', $ordersUrl, [
            'supplier_id' => $supplierA->id(), 'lines' => [],
        ], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(404);
    }
}
'''

ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = ROOT / "src/Http/Controller/PurchasingController.php"
SUPPORT = ROOT / "src/Http/Controller/BranchApiSupport.php"
ORDERS = ROOT / "src/Application/Purchasing/PurchaseOrderService.php"
PHP_DOMAIN_TEST = ROOT / "tests/php/Domain/Purchasing/PurchaseOrderDomainTest.php"


class ComprasApiHttpTests(unittest.TestCase):
    def test_acceptance(self) -> None:
        controller = CONTROLLER.read_text(encoding="utf-8")
        support = SUPPORT.read_text(encoding="utf-8")
        service = ORDERS.read_text(encoding="utf-8")
        self.assertIn("use BranchApiSupport;", controller)
        self.assertIn("$this->currentTenantForUser->resolve($user)", support)
        self.assertIn("$this->authorization->require(", support)
        self.assertIn("'tenant' => $tenant", support)
        self.assertIn("requireCsrf(Request $request)", support)
        self.assertIn("array_diff(", support)

        for route in (
            "/purchasing/suppliers", "/purchasing/orders", "/receive"
        ):
            self.assertIn(route, controller)
        for permission in ("inventory.view", "inventory.create", "inventory.update"):
            self.assertIn(f"'{permission}'", controller)

        methods = re.findall(
            r"public function (\w+)\([^)]*\): JsonResponse\s*\{(.*?)(?=\n    (?:public|private) function|\n    /\*\*|\n    #\[Route|\n\})",
            controller,
            flags=re.S,
        )
        self.assertGreaterEqual(len(methods), 5)
        for name, body in methods:
            with self.subTest(action=name):
                self.assertIn("$this->authorizedBranchScope(", body)
                if name.startswith("create") or name.startswith("receive"):
                    self.assertIn("$this->requireCsrf($request)", body)

        for contract in (
            "'tenant' => $tenant",
            "'legalEntity' => $source->legalEntity()",
            "'branch' => $branch",
            "'active' => true",
            "'id' => $materialId",
            "'id' => $id",
        ):
            self.assertIn(contract, controller)
        self.assertIn("is_array($submitted)", controller)
        self.assertIn("array_is_list($submitted)", controller)
        self.assertIn("get_object_vars($line)", controller)
        self.assertIn("campos no permitidos", controller)
        self.assertNotIn("$payload['tenant_id']", controller)
        self.assertNotIn("$payload['legal_entity_id']", controller)
        self.assertNotIn("$payload['source_id']", controller)
        self.assertIn("$this->purchaseOrders->receive(", controller)
        self.assertIn("$alreadyReceived = $order->isReceived()", controller)
        # Concurrent replays must acquire the database lock before the
        # already-received decision; stock receipt and audit share a transaction.
        receipt = controller.split("public function receiveOrder(", 1)[1].split(
            "private function branchSource(", 1
        )[0]
        self.assertIn("return $this->entityManager->wrapInTransaction(", receipt)
        self.assertIn("refresh($order, LockMode::PESSIMISTIC_WRITE)", receipt)
        self.assertLess(
            receipt.index("refresh($order, LockMode::PESSIMISTIC_WRITE)"),
            receipt.index("$alreadyReceived = $order->isReceived()"),
        )
        self.assertLess(
            receipt.index("$alreadyReceived = $order->isReceived()"),
            receipt.index("$this->purchaseOrders->receive("),
        )
        self.assertLess(
            receipt.index("$this->purchaseOrders->receive("),
            receipt.index("purchasing_order.received"),
        )
        self.assertNotIn("$this->entityManager->flush();", receipt)
        self.assertIn("$alreadyReceived ? Response::HTTP_OK : Response::HTTP_CREATED", controller)
        self.assertIn("if (!$alreadyReceived)", controller)
        self.assertIn("$this->audit(", controller)
        self.assertIn("array_key_exists('payment_terms_days', $payload)", controller)
        self.assertIn("$this->branchSource($tenant, $branch, false)", controller)
        self.assertIn("!$alreadyReceived && !$source->isActive()", controller)
        self.assertIn("wrapInTransaction", service)
        self.assertIn("LockMode::PESSIMISTIC_WRITE", service)
        self.assertIn("$order->assertSameReceipt($key, $source->id())", service)

        # Run actual authenticated HTTP requests against synthetic tenants/branches.
        # Keep the generated PHPUnit fixture in a temporary directory rather than
        # adding a file outside this issue's reserved repository paths.
        with tempfile.TemporaryDirectory(prefix="condor-purchasing-http-") as tempdir:
            smoke = Path(tempdir) / "PurchasingScopeSmokeTest.php"
            smoke.write_text(PHP_PURCHASING_SCOPE_SMOKE, encoding="utf-8")
            result = subprocess.run(
                [str(runner), str(smoke)],
                cwd=ROOT, capture_output=True, text=True, check=False,
            )
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)

        php = shutil.which("php")
        self.assertIsNotNone(php, "El PHP CLI es necesario para validar la API")
        syntax = subprocess.run(
            [php, "-l", str(CONTROLLER)], capture_output=True, text=True,
            check=False,
        )
        self.assertEqual(0, syntax.returncode, syntax.stdout + syntax.stderr)
        runner = ROOT / "vendor/bin/simple-phpunit"
        self.assertTrue(runner.exists(), "PHPUnit instalado necesario para comprobar idempotencia")
        for pattern in (
            "testPurchaseOrderRejectsCrossTenantSupplierAndMaterial",
            "testPurchaseOrderStateIsMonotonicAndIdempotent",
            "testReceiptReplayAfterDeactivationAndLegalEntityIsolation",
        ):
            with self.subTest(domain=pattern):
                result = subprocess.run(
                    [str(runner), "--filter", pattern, str(PHP_DOMAIN_TEST)],
                    cwd=ROOT, capture_output=True, text=True, check=False,
                )
                self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
