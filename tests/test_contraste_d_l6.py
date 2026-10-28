#!/usr/bin/env python3
"""Condor #1185: evidencia EJECUTADA y limitada de D-033/035/036/037.

Las especificaciones contienen alcances adicionales aún NO_VERIFICABLES:
no se infiere cobertura integral, PR MERGED ni validación en producción.
Fixtures ficticios; usa PHP real pero sustituye exclusivamente ULID de Symfony.
"""
from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

from scripts.ci_throughput_report import comparable_runs, regression_result

ROOT = Path(__file__).resolve().parents[1]
PHP_BOOTSTRAP = r"""
declare(strict_types=1);
error_reporting(E_ALL);
if (!class_exists('Symfony\\Component\\Uid\\Ulid')) {
    eval('namespace Symfony\\Component\\Uid; final class Ulid {
        private static int $next = 0;
        public function __toString(): string {
            return str_pad((string) ++self::$next, 26, "0", STR_PAD_LEFT);
        }
    }');
}
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $path = getcwd() . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (!is_file($path)) { throw new RuntimeException('Clase ausente'); }
        require $path;
    }
});
function exact(bool $valid, string $why): void {
    if (!$valid) { throw new RuntimeException('Falló: ' . $why); }
}
function rejected(callable $fn): void {
    try { $fn(); } catch (DomainException $ex) { return; }
    throw new RuntimeException('Se esperaba DomainException');
}
"""


class ContrasteDL6Tests(unittest.TestCase):
    """Prueba un corte representativo sin ampliar el alcance de D-033..037."""

    def php(self, scenario: str) -> None:
        executable = shutil.which("php")
        self.assertIsNotNone(executable, "Sin PHP CLI auténtico no hay evidencia")
        process = subprocess.run(
            [executable, "-n", "-d", "display_errors=stderr", "-r",
             PHP_BOOTSTRAP + "\n" + scenario + '\necho "OK\\n";'],
            cwd=ROOT, capture_output=True, text=True, check=False, timeout=30,
        )
        self.assertEqual(process.returncode, 0, process.stderr[-2500:])
        self.assertEqual(process.stdout, "OK\n", "Salida ajena al contrato")

    def test_contrato_principal(self) -> None:
        # D-033: tema, página y bloque guardan identidad tenant, estado y tipo.
        # D-035: motor evalúa un evento sin ejecutar side effects.
        # D-037: caso de uso API ficticio con scope independiente de la UI.
        self.php(r"""
$a = new App\Domain\Organization\Entity\Tenant('Empresa ficticia A', 'demo-a');
$b = new App\Domain\Organization\Entity\Tenant('Empresa ficticia B', 'demo-b');
$theme = new App\Domain\Cms\Entity\CmsTheme($a, 'base', 'Tema');
$page = new App\Domain\Cms\Entity\CmsPage($a, $theme, 'inicio', 'Inicio');
$block = new App\Domain\Cms\Entity\CmsBlock($a, $page, 'hero', ['headline' => 'Ficticio']);
exact($theme->tenant()->id() === $a->id(), 'tema tenant');
exact($block->page() === $page && $block->tenant()->id() === $a->id(), 'bloque tenant');
exact(!$page->isPublished(), 'borrador por defecto');
$page->publish();
exact($page->isPublished(), 'publicación controlada');
$workflow = new App\Domain\Workflow\WorkflowDefinition(
    'tenant-a', 'wf-demo', 'stock.low',
    [['field' => 'stock.quantity', 'operator' => 'eq', 'value' => 0]],
    [['type' => 'inventory.restock', 'arguments' => ['note' => 'PRIVATE_FIXTURE']]]
);
$engine = new App\Domain\Workflow\WorkflowEngine(['inventory.restock' => 'inventory.manage']);
$trace = $engine->evaluate(
    $workflow, 'tenant-a', 'stock.low',
    ['stock' => ['quantity' => 0]], ['inventory.manage']
)->toArray();
exact($trace['status'] === 'planned' && $trace['reason'] === 'conditions_matched', 'plan');
exact($trace['planned_actions'] === ['inventory.restock'], 'orden');
exact(!str_contains(json_encode($trace, JSON_THROW_ON_ERROR), 'PRIVATE_FIXTURE'), 'traza mínima');
$scope = hash('sha256', implode('|', ['tenant-a', 'company-a', 'branch-a']));
$created = App\Application\Contracts\ContractApiService::createRecord(
    [], $scope, ['kind' => 'contract', 'ref' => 'fixture:contract/sample']
);
exact($created['record']['synthetic_only'] === true, 'solo fixture');
exact(count(App\Application\Contracts\ContractApiService::listRecords(
    $created['store'], $scope
)) === 1, 'consulta API');
exact(App\Application\Contracts\ContractApiService::viewRecord(
    $created['store'], hash('sha256', 'tenant-b|company-a|branch-a'),
    'fixture:contract/sample'
) === null, 'API aislada por tenant');
""")
        # No se afirma que el API real completo sea equivalente a móvil:
        # el controlador debe usar autorización backend, no permisos de UI.
        controller = (ROOT / "src/Http/Controller/ContractsController.php").read_text()
        self.assertIn("use BranchApiSupport;", controller)
        self.assertIn("$this->authorizedBranchScope($branchId, $permission)", controller)
        context = (ROOT / "src/Http/Controller/ApiContextController.php").read_text()
        self.assertIn("'/api/v1/context'", context)
        self.assertIn("AccessDeniedHttpException", context)
        # D-036: clasificador real, evidencia de regresión sin afirmar live.
        samples = [{"duration_seconds": 20} for _ in range(3)]
        self.assertEqual("regression", regression_result(40, samples, "success")["status"])
        self.assertEqual("normal", regression_result(22, samples, "success")["status"])

    def test_falla_cerrado(self) -> None:
        # Los negativos ejercitan clases reales, no solamente búsquedas de texto.
        self.php(r"""
$a = new App\Domain\Organization\Entity\Tenant('A ficticia', 'demo-a');
$b = new App\Domain\Organization\Entity\Tenant('B ficticia', 'demo-b');
$theme = new App\Domain\Cms\Entity\CmsTheme($a, 'base', 'Tema');
$page = new App\Domain\Cms\Entity\CmsPage($a, $theme, 'inicio', 'Inicio');
rejected(static fn() => new App\Domain\Cms\Entity\CmsPage(
    $b, $theme, 'ajena', 'Intrusa'
));
rejected(static fn() => new App\Domain\Cms\Entity\CmsBlock(
    $b, $page, 'text', ['text' => 'invasión']
));
rejected(static fn() => new App\Domain\Cms\Entity\CmsBlock(
    $a, $page, 'script', ['text' => 'no ejecutar']
));
$page->publish();
rejected(static fn() => new App\Domain\Cms\Entity\CmsBlock($a, $page, 'text'));
$definition = new App\Domain\Workflow\WorkflowDefinition(
    'tenant-a', 'wf-demo', 'stock.low', [], [['type' => 'inventory.restock']]
);
$engine = new App\Domain\Workflow\WorkflowEngine(['inventory.restock' => 'inventory.manage']);
rejected(static fn() => $engine->evaluate($definition, 'tenant-b', 'stock.low', [], ['inventory.manage']));
rejected(static fn() => $engine->evaluate($definition, 'tenant-a', 'stock.low', [], []));
rejected(static fn() => $engine->evaluate(
    new App\Domain\Workflow\WorkflowDefinition(
        'tenant-a', 'bad', 'stock.low', [], [['type' => 'webhook.send']]
    ), 'tenant-a', 'stock.low', [], ['inventory.manage']
));
$scope = hash('sha256', 'tenant-a|company-a|branch-a');
rejected(static fn() => App\Application\Contracts\ContractApiService::createRecord(
    [], $scope, ['kind' => 'contract', 'ref' => 'someone@example.com']
));
rejected(static fn() => App\Application\Contracts\ContractApiService::listRecords([], 'not-a-scope'));
""")
        self.assertEqual("not_evaluated", regression_result(
            40, [{"duration_seconds": 20}] * 3, "failure",
        )["status"])

    def test_sin_datos_reales(self) -> None:
        # El constructor del dominio solo acepta referencias con prefijo fixture:.
        self.php(r"""
$scope = hash('sha256', 'synthetic|tenant|branch');
$r = App\Application\Contracts\ContractApiService::createRecord(
    [], $scope, ['kind' => 'template', 'ref' => 'fixture:template/blank']
);
exact($r['record']['synthetic_only'] === true, 'fixture obligatoria');
exact(!array_key_exists('email', $r['record']), 'sin PII');
exact(!array_key_exists('token', $r['record']), 'sin secretos');
rejected(static fn() => App\Application\Contracts\ContractApiService::createRecord(
    [], $scope, ['kind' => 'contract', 'ref' => 'fixture:contract/person3001234567']
));
""")
        source = (ROOT / "src/Application/Contracts/ContractApiService.php").read_text()
        for forbidden in ("curl_init(", "httpClient", "file_get_contents(", "getenv("):
            self.assertNotIn(forbidden, source)

    def test_estados_unknown_explicitos(self) -> None:
        # Sin historia de CI, nunca clasificar como 'normal'.
        self.assertEqual([], comparable_runs(None, 99, "pull_request"))
        self.assertEqual([], comparable_runs(
            {"workflow_runs": "UNKNOWN"}, 99, "pull_request"
        ))
        self.assertEqual(
            "insufficient_baseline",
            regression_result(120, [{"duration_seconds": 20}], "success")["status"],
        )
        # Evento ajeno o desactivado no puede transformarse en plan ejecutable.
        self.php(r"""
$definition = new App\Domain\Workflow\WorkflowDefinition(
    'tenant-a', 'wf-demo', 'inventory.low', [],
    [['type' => 'inventory.restock']], false
);
$engine = new App\Domain\Workflow\WorkflowEngine(['inventory.restock' => 'inventory.manage']);
$trace = $engine->evaluate(
    $definition, 'tenant-a', 'inventory.low', [], ['inventory.manage']
);
exact($trace->status === 'skipped' && $trace->reason === 'inactive', 'workflow inactivo');
$enabled = new App\Domain\Workflow\WorkflowDefinition(
    'tenant-a', 'wf-demo', 'inventory.low',
    [['field' => 'inventory.amount', 'operator' => 'exists']],
    [['type' => 'inventory.restock']]
);
$missing = $engine->evaluate(
    $enabled, 'tenant-a', 'inventory.low', [], ['inventory.manage']
);
exact($missing->status === 'skipped', 'campo ausente');
exact($missing->reason === 'conditions_not_matched', 'razón explícita');
exact($missing->plannedActions === [], 'no plan sin datos');
""")
        # Una prueba aislada no acredita SEO, integraciones externas, restore,
        # observabilidad integral, apps móviles ni un despliegue real.

if __name__ == "__main__":
    unittest.main()
