#!/usr/bin/env python3
"""#1967: enumerate Symfony public mutations, exercise guard with real RequestEvents.

All inputs are synthetic. The fake controller counter never invokes a real
controller, sends an email, creates a tenant or touches a production datastore.
The Symfony route inventory comes from debug:router, not a hand-written list.
"""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
GUARD = ROOT / "src/Infrastructure/Security/PublicAbuseGuard.php"
CONFIG = ROOT / "config/packages/rate_limiter.yaml"

PHP_HARNESS = r"""<?php
declare(strict_types=1);

use App\Application\Onboarding\PublicTrialCaptcha;
use App\Infrastructure\Security\PublicAbuseGuard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

require __DIR__ . '/vendor/autoload.php';

$routes = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
$storage = new InMemoryStorage();
$factories = [];
foreach ([['ip', 24], ['identifier', 12], ['email', 5]] as [$name, $count]) {
    $factories[] = new RateLimiterFactory([
        'id' => 'public_test_'.$name,
        'policy' => 'sliding_window',
        'limit' => $count,
        'interval' => $name === 'email' ? '1 hour' : '5 minutes',
    ], $storage);
}
$guard = new PublicAbuseGuard(...$factories);
$kernel = new class implements HttpKernelInterface {
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response {
        return new Response('simulated-side-effect');
    }
};
$make = static function (string $name, string $path, string $ip, array $params = []) use ($kernel, $guard): RequestEvent {
    $request = Request::create($path, 'POST', $params, [], [], ['REMOTE_ADDR' => $ip]);
    $request->attributes->set('_route', $name);
    $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    $guard->onKernelRequest($event);
    return $event;
};

// Each discovered public POST route, including an unknown future route, gets
// 50 requests from the same source and can never execute a fake side effect
// once throttled. A unique IP per route avoids cross-test pollution.
$result = [];
foreach ($routes as $index => $route) {
    [$name, $path] = $route;
    if (!PublicAbuseGuard::isPublicMutationPath($path)) {
        throw new RuntimeException('Inventario público no cubierto: '.$path);
    }
    $ip = '198.51.100.'.(($index % 200) + 1);
    $accepted = 0;
    $throttled = 0;
    for ($number = 1; $number <= 50; ++$number) {
        $event = $make($name, $path, $ip);
        if (!$event->hasResponse()) {
            ++$accepted;
            if ($number > 24) {
                throw new RuntimeException('Sin límite tras umbral: '.$name);
            }
            continue;
        }
        $response = $event->getResponse();
        if ($response->getStatusCode() !== 429
            || (int) $response->headers->get('Retry-After') < 1) {
            throw new RuntimeException('No devuelve 429 + Retry-After: '.$name);
        }
        ++$throttled;
    }
    if ($accepted < 1 || $accepted > 24 || $throttled < 26) {
        throw new RuntimeException('Cuota irregular: '.$name.' '.$accepted);
    }
    $result[$name] = [$accepted, $throttled];
}
// Synthetic novel endpoint: the policy must apply without editing a route
// allowlist. A honeypot must reject before a controller is ever reached.
for ($i = 0; $i < 50; ++$i) {
    $event = $make('future_public_contact_'.$i, '/contacto-futuro', '192.0.2.99');
    if ($i >= 24 && (!$event->hasResponse() || $event->getResponse()->getStatusCode() !== 429)) {
        throw new RuntimeException('El formulario nuevo no está protegido.');
    }
}
// Distinct visitors without an account identifier must not consume a
// shared global anonymous bucket (availability/DoS regression).
$visitorA = $make('future_public_anonymous', '/formulario-futuro', '192.0.2.118');
$visitorB = $make('future_public_anonymous', '/formulario-futuro', '192.0.2.119');
if ($visitorA->hasResponse() || $visitorB->hasResponse()) {
    throw new RuntimeException('Un visitante agotó la cuota de otro.');
}
$trap = $make('future_public_contact', '/contacto-futuro', '192.0.2.100', ['_condor_website' => 'spam']);
if (!$trap->hasResponse() || $trap->getResponse()->getStatusCode() !== 422) {
    throw new RuntimeException('El honeypot no rechazó el envío.');
}
$invalidArea = $make('protected_edit', '/api/v1/internal/edit', '192.0.2.101');
if ($invalidArea->hasResponse()) {
    throw new RuntimeException('Guard alteró un endpoint autenticado.');
}

$captchaFake = new class extends PublicTrialCaptcha {
    public function verifyForAction(string $token, string $action): bool {
        return $token === 'fixture-valid-token' && in_array($action,
            ['condor_password_reset', 'condor_storefront_checkout'], true);
    }
};
$signingKey = 'fixture-only-signing-secret-long-enough';
$strict = new PublicAbuseGuard($factories[0], $factories[1], $factories[2],
    $signingKey, 'prod', $captchaFake);
$get = Request::create('/admin/recuperar-contrasena', 'GET');
$get->attributes->set('_route', 'app_password_reset_request');
$page = new ResponseEvent($kernel, $get, HttpKernelInterface::MAIN_REQUEST,
    new Response('<html><form method="post"><button>Enviar</button></form></html>',
        200, ['Content-Type' => 'text/html']));
$strict->onKernelResponse($page);
$html = $page->getResponse()->getContent();
if (!is_string($html)
    || preg_match('/name="_condor_form_proof" value="([^"]+)"/', $html, $found) !== 1
    || !str_contains($html, 'name="_condor_website"')) {
    throw new RuntimeException('GET no generó prueba firmada y honeypot.');
}
$post = static function (array $data, string $ip) use ($strict, $kernel): RequestEvent {
    $request = Request::create('/admin/recuperar-contrasena', 'POST', $data, [], [],
        ['REMOTE_ADDR' => $ip, 'CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
    $request->attributes->set('_route', 'app_password_reset_request');
    $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    $strict->onKernelRequest($event);
    return $event;
};
$fields = ['email' => 'fixture@example.test', '_condor_form_proof' => $found[1],
    'cf-turnstile-response' => 'fixture-valid-token'];
$tooFast = $post($fields, '192.0.2.201');
if (!$tooFast->hasResponse() || $tooFast->getResponse()->getStatusCode() !== 422) {
    throw new RuntimeException('La protección de tiempo no rechazó una ráfaga inmediata.');
}
$issued = time() - 3;
$fields['_condor_form_proof'] = $issued.'.'.hash_hmac(
    'sha256', $issued.'|/admin/recuperar-contrasena', $signingKey);
$accepted = $post($fields, '192.0.2.202');
if ($accepted->hasResponse()
    || $accepted->getRequest()->request->has('_condor_form_proof')) {
    throw new RuntimeException('Un formulario válido fue rechazado.');
}
$invalid = $post([...$fields, 'cf-turnstile-response' => 'fake'], '192.0.2.203');
if (!$invalid->hasResponse() || $invalid->getResponse()->getStatusCode() !== 422) {
    throw new RuntimeException('Se aceptó Turnstile inválido.');
}
$honeypot = $post([...$fields, '_condor_website' => 'filled'], '192.0.2.204');
if (!$honeypot->hasResponse() || $honeypot->getResponse()->getStatusCode() !== 422) {
    throw new RuntimeException('Se aceptó el honeypot lleno.');
}
$checkout = Request::create('/checkout', 'POST', [], [], [],
    ['REMOTE_ADDR' => '192.0.2.205', 'CONTENT_TYPE' => 'application/json'],
    json_encode(['idempotency_key' => 'fixture-key', 'items' => [],
        'captcha' => 'fixture-valid-token'], JSON_THROW_ON_ERROR));
$checkout->attributes->set('_route', 'app_storefront_checkout_domain');
$checkoutEvent = new RequestEvent($kernel, $checkout, HttpKernelInterface::MAIN_REQUEST);
$strict->onKernelRequest($checkoutEvent);
if ($checkoutEvent->hasResponse()) {
    throw new RuntimeException('Checkout válido con Turnstile rechazado.');
}

echo json_encode(['discovered' => count($result), 'routes' => $result, 'honeypot' => true], JSON_THROW_ON_ERROR);
"""

class PublicEndpointsAbuseAcceptance(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if shutil.which("php") is None:
            raise unittest.SkipTest("PHP no disponible")
        cls.has_vendor = (ROOT / "vendor/autoload.php").is_file()
        if not cls.has_vendor:
            raise unittest.SkipTest("Requiere composer install (job backend PHP)")
        cls.inventory = cls._discover()

    @staticmethod
    def _discover():
        proc = subprocess.run(
            ["php", "bin/console", "debug:router", "--format=json"],
            cwd=ROOT, env={**os.environ, "APP_ENV": "test"},
            capture_output=True, text=True, timeout=90, check=False,
        )
        if proc.returncode:
            raise AssertionError("debug:router falló: " + proc.stderr[-1500:])
        try:
            routes = json.loads(proc.stdout)
        except json.JSONDecodeError as exc:
            raise AssertionError("Symfony no entregó inventario JSON") from exc

        if isinstance(routes, dict):
            entries = [(name, details) for name, details in routes.items()]
        elif isinstance(routes, list):
            entries = [(r.get("name"), r) for r in routes]
        else:
            raise AssertionError("Formato de rutas no reconocido")

        def public_path(path):
            if path == "/api/v1" or path.startswith("/api/v1/"):
                return False
            if path.startswith("/adminpl0n3r"):
                return False
            if path == "/ops" or path.startswith("/ops/"):
                return False
            if path.startswith("/admin"):
                return path in (
                    "/admin/login", "/admin/recuperar-contrasena",
                    "/admin/restablecer-contrasena",
                )
            return not path.startswith("/_")

        discovered = []
        for name, details in entries:
            if not isinstance(details, dict):
                continue
            path = details.get("path") or details.get("route")
            methods = details.get("method", details.get("methods", "ANY"))
            if not isinstance(name, str) or not isinstance(path, str):
                continue
            normalized = (
                [str(x).upper() for x in methods] if isinstance(methods, list)
                else re.split(r"[|,\s]+", str(methods).upper())
            )
            can_mutate = (not methods or "ANY" in normalized
                          or any(m in ("POST", "PUT", "PATCH") for m in normalized))
            if can_mutate and public_path(path):
                # Replace Symfony placeholders only for Request path inspection.
                concrete = re.sub(r"\{[^}]+\}", "a" * 64, path)
                discovered.append([name, concrete])

        assert discovered, "No se detectó ninguna ruta pública mutante"
        assert any("trial" in name for name, _ in discovered), (
            "Falta el endpoint de alta pública en debug:router"
        )
        assert any("checkout" in name for name, _ in discovered), (
            "Falta checkout en el inventario público"
        )
        return discovered

    def test_discovery_includes_every_future_public_mutation(self):
        self.assertTrue(self.inventory)
        self.assertTrue(any("password" in name for name, _ in self.inventory))
        self.assertFalse(any(path.startswith("/api/v1/") for _, path in self.inventory))

    def test_50_requests_and_honeypot_fail_closed(self):
        with tempfile.TemporaryDirectory(prefix="condor-public-abuse-") as directory:
            routes = Path(directory) / "routes.json"
            routes.write_text(json.dumps(self.inventory), encoding="utf-8")
            # PHP's __DIR__ is the checkout root when fixture is placed there.
            # A temporary file outside the repo cannot load relative vendor.
            script = PHP_HARNESS.replace(
                "require __DIR__ . '/vendor/autoload.php';",
                "require " + json.dumps(str(ROOT / "vendor/autoload.php")) + ";",
            )
            fixture = Path(directory) / "fixture.php"
            fixture.write_text(script, encoding="utf-8")
            result = subprocess.run(
                ["php", str(fixture), str(routes)],
                cwd=ROOT, env={**os.environ, "APP_ENV": "test"},
                capture_output=True, text=True, timeout=90, check=False,
            )
            self.assertEqual(result.returncode, 0, result.stderr[-2500:] + result.stdout[-500:])
            data = json.loads(result.stdout)
            self.assertEqual(data["discovered"], len(self.inventory))
            self.assertTrue(data["honeypot"])
            for accepted, throttled in data["routes"].values():
                self.assertLessEqual(accepted, 24)
                self.assertGreaterEqual(throttled, 26)

    def test_limits_have_explicit_budgets(self):
        content = CONFIG.read_text(encoding="utf-8")
        for name in ("public_abuse_ip", "public_abuse_identifier", "public_email_recipient"):
            self.assertIn(name + ":", content)
        self.assertIn("priority: 9", GUARD.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main(verbosity=2)
