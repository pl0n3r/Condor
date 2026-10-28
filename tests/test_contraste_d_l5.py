#!/usr/bin/env python3
"""Condor #1184: contratos sintéticos D-030-S01/S03/S04 y D-031.

Prueba comportamiento PHP existente sin Composer, red, secretos, base de datos
ni operaciones reales. El adaptador sintético de ULID no acredita su generador.
El resultado NO verifica la propuesta comercial completa ni producción.
"""
from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

# Solo se sustituye la fuente de IDs y la interfaz de reset que normalmente
# llegan de Symfony. Las entidades, reglas y errores vienen del src/ real.
PHP_BOOTSTRAP = r'''
declare(strict_types=1);
error_reporting(E_ALL);
if (!interface_exists('Symfony\\Contracts\\Service\\ResetInterface')) {
    eval('namespace Symfony\\Contracts\\Service; interface ResetInterface { public function reset(): void; }');
}
if (!class_exists('Symfony\\Component\\Uid\\Ulid')) {
    eval('namespace Symfony\\Component\\Uid; final class Ulid {
        private static int $next = 0;
        public function __toString(): string {
            return str_pad((string) ++self::$next, 26, "0", STR_PAD_LEFT);
        }
    }');
}
if (!interface_exists('Doctrine\\Common\\Collections\\Collection')) {
    eval('namespace Doctrine\\Common\\Collections; interface Collection {}');
}
if (!class_exists('Doctrine\\Common\\Collections\\ArrayCollection')) {
    eval('namespace Doctrine\\Common\\Collections; final class ArrayCollection implements Collection {
        public function __construct(array $items = []) {}
    }');
}
spl_autoload_register(static function (string $name): void {
    if (!str_starts_with($name, 'App\\')) {
        return;
    }
    $path = getcwd() . '/src/' . str_replace('\\', '/', substr($name, 4)) . '.php';
    if (!is_file($path)) {
        throw new RuntimeException('Clase de dominio ausente: ' . $name);
    }
    require $path;
});
function exact(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('Contraste incumplido: ' . $message);
    }
}
function reject(callable $operation, string $expected): void {
    try {
        $operation();
    } catch (Throwable $e) {
        if ($e instanceof $expected) {
            return;
        }
        throw new RuntimeException('Error inesperado: ' . get_class($e));
    }
    throw new RuntimeException('Falta rechazo ' . $expected);
}
'''


class ContrasteDL5Tests(unittest.TestCase):
    def php(self, scenario: str) -> None:
        executable = shutil.which("php")
        self.assertIsNotNone(executable, "PHP es obligatorio: ausencia != OK")
        result = subprocess.run(
            [executable, "-d", "display_errors=stderr", "-r",
             PHP_BOOTSTRAP + "\n" + scenario + "\necho \"OK\\n\";\n"],
            cwd=ROOT, capture_output=True, text=True, check=False, timeout=25,
        )
        self.assertEqual(
            result.returncode, 0,
            f"Contrato PHP falló cerrado (exit={result.returncode}): "
            + result.stderr[-1500:],
        )
        self.assertEqual(result.stdout, "OK\n", "Salida inesperada/filtración")

    def test_contrato_principal(self) -> None:
        # D-030-S01: el modelo organizacional acepta verticales diferentes,
        # sin imponer sector. D-030-S03: tenant, entidad legal, sede y cliente
        # son identidades separadas vinculadas estructuralmente.
        # D-030-S04: límites configurables con PlanVersion explícita.
        # D-031: pertenencia y ULIDs opacos a nivel de modelo.
        self.php(r'''
$tenantA = new App\Domain\Organization\Entity\Tenant('Taller ficticio', 'TALLER-FICTICIO');
$tenantB = new App\Domain\Organization\Entity\Tenant('Comercio ficticio', 'COMERCIO-FICTICIO');
exact($tenantA->slug() === 'taller-ficticio', 'normalización general de tenant');
exact($tenantB->slug() === 'comercio-ficticio', 'sin sector obligatorio');
exact($tenantA->id() !== $tenantB->id(), 'identidad tenant disjunta');
exact(strlen($tenantA->id()) === 26, 'contrato id string 26 (fixture ULID)');
$legal = new App\Domain\Organization\Entity\LegalEntity($tenantA, 'Persona jurídica ficticia', null);
$branch = new App\Domain\Organization\Entity\Branch($tenantA, 'Principal', 'PRINCIPAL', $legal);
$customer = new App\Domain\Commerce\Entity\Customer($tenantA, 'Cliente ficticio');
exact($legal->tenant()->id() === $tenantA->id(), 'entidad legal tenant-owned');
exact($branch->legalEntity()?->id() === $legal->id(), 'sede referencia entidad legal');
exact($branch->slug() === 'principal', 'sede normalizada');
exact($customer->tenant()->id() === $tenantA->id(), 'cliente no usuario');
exact(get_class($customer) !== get_class($tenantA), 'términos de dominio separados');
$plan = new App\Domain\Commercial\Entity\Plan('general', 'Plan ficticio');
$version = new App\Domain\Commercial\Entity\PlanVersion(
    $plan, 2, 25000, null, false,
    ['users' => 2, 'guest_checkout' => true],
    new DateTimeImmutable('2026-01-01T00:00:00+00:00')
);
exact($version->version() === 2, 'configuración versionada');
exact($version->limits()['users'] === 2, 'límite configurable');
exact($version->limits()['guest_checkout'] === true, 'regla configurable');
exact(
    !App\Application\Commercial\PlanConfigurationRules::isSelectableAddOn('extra-user'),
    'invariante: escalado no addon libre'
);
exact(
    App\Application\Commercial\PlanConfigurationRules::isSelectableAddOn('seo'),
    'addon permitido'
);
''')

    def test_falla_cerrado(self) -> None:
        # Negativos reales de D-030-S03/S04/D-031: referencias cross-tenant,
        # configuraciones sin versión válida, clave desconocida y reglas
        # incompatibles se rechazan; no hay fallback exitoso.
        self.php(r'''
$a = new App\Domain\Organization\Entity\Tenant('A sintética', 'a-sintetica');
$b = new App\Domain\Organization\Entity\Tenant('B sintética', 'b-sintetica');
$foreign = new App\Domain\Organization\Entity\LegalEntity($b, 'Legal B ficticia', null);
reject(
    static fn () => new App\Domain\Organization\Entity\Branch(
        $a, 'Principal', 'principal', $foreign
    ),
    DomainException::class
);
reject(
    static fn () => new App\Domain\Commercial\Entity\Plan('bad key', 'Inválido'),
    DomainException::class
);
$plan = new App\Domain\Commercial\Entity\Plan('general', 'General');
$at = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
reject(
    static fn () => new App\Domain\Commercial\Entity\PlanVersion(
        $plan, 0, 25000, null, false, ['users' => 2], $at
    ),
    DomainException::class
);
reject(
    static fn () => new App\Domain\Commercial\Entity\PlanVersion(
        $plan, 1, 25000, null, false, ['cross-tenant' => 2], $at
    ),
    DomainException::class
);
reject(
    static fn () => new App\Domain\Commercial\Entity\PlanVersion(
        $plan, 1, 25000, null, false, ['users' => -1], $at
    ),
    DomainException::class
);
''')

    def test_sin_datos_reales(self) -> None:
        # Fixtures aislados: sin PII, tokens, red, DB o roles de acceso reales.
        # No se imprimen siquiera los nombres sintéticos de los objetos.
        self.php(r'''
$tenant = new App\Domain\Organization\Entity\Tenant('Ficticia', 'ficticia');
$customer = new App\Domain\Commerce\Entity\Customer($tenant, 'Cliente ficticio');
exact($customer->email() === null, 'sin correo personal');
exact($customer->phone() === null, 'sin teléfono real');
exact($customer->documentNumber() === null, 'sin identificación');
exact($customer->tenant()->id() === $tenant->id(), 'scoping local');
''')

    def test_estados_unknown_explicitos(self) -> None:
        # D-031: sin contexto explícito no se puede operar; tenant distinto
        # en la misma petición se rechaza; reset no hereda contexto anterior.
        self.php(r'''
$context = new App\Infrastructure\Tenancy\TenantContext();
reject(static fn () => $context->require(), LogicException::class);
$a = new App\Domain\Organization\Entity\Tenant('Empresa A ficticia', 'empresa-a');
$b = new App\Domain\Organization\Entity\Tenant('Empresa B ficticia', 'empresa-b');
$context->set($a);
exact($context->require()->id() === $a->id(), 'contexto explícito');
reject(static fn () => $context->set($b), LogicException::class);
exact($context->require()->id() === $a->id(), 'sin cambio tras error');
$context->reset();
reject(static fn () => $context->require(), LogicException::class);
$context->set($b);
exact($context->require()->id() === $b->id(), 'nueva petición aislada');
''')


if __name__ == "__main__":
    unittest.main()
