#!/usr/bin/env python3
"""Referencia OpenAPI reproducible desde atributos Symfony (Condor #1691).

Uso:
    python3 tests/test_openapi_condor_referencia.py --write
    python3 tests/test_openapi_condor_referencia.py --check
    python3 -m unittest discover -s tests -p 'test_openapi_condor_referencia.py'
Sin Symfony en ejecución, red, credenciales ni datos de clientes.
"""
from __future__ import annotations

import json
import re
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROLLERS = ROOT / "src" / "Http" / "Controller"
REFERENCE = ROOT / "openapi" / "condor-admin-v1.json"
ATTR = re.compile(r"#\[Route\(([\s\S]*?)\)\]")
CLASS = re.compile(r"\bclass\s+\w+")
FUNCTION = re.compile(r"\bpublic function\s+(\w+)\s*\(")
NEXT_FUNCTION = re.compile(r"\n\s*(?:(?:public|protected|private)\s+function\s+|#\[Route\()")
PATH = re.compile(r"^\s*(['\"])(.*?)\1", re.S)
METHODS = re.compile(r"methods:\s*\[([^\]]+)\]")
PERMISSION = re.compile(
    r"(?:authorizedScope|authorizedBranchScope|access|requirePermission|assertPermission)"
    r"\s*\([\s\S]{0,220}?'([a-z][\w-]*\.[a-z][\w-]*)'"
)
QUERY = re.compile(
    r"(?:->query->(?:get|getInt|getBoolean|all|has)|->query\.get)\s*\(\s*'([^']+)'"
)
PAYLOAD = re.compile(r"->payload\s*\([\s\S]{0,85}?\[([\s\S]{0,500}?)\]")
FIELD = re.compile(r"'([^']+)'")
VARIABLE_PERMISSION = re.compile(
    r"(?:secureScope|productionScope|respond|occupationalAccess)"
    r"\s*\([\s\S]{0,190}?'([a-z][\w-]*\.[a-z][\w-]*)'"
)
ERRORS = {
    "400": "bad_request", "401": "unauthenticated",
    "403": "forbidden", "404": "not_found", "409": "conflict",
    "422": "validation_error", "429": "rate_limited",
}


def unique(values: list[str]) -> list[str]:
    return list(dict.fromkeys(values))


def relevant(path: str) -> bool:
    return (
        path == "/api/v1" or path.startswith("/api/v1/")
        or path == "/adminpl0n3r/api"
        or path.startswith("/adminpl0n3r/api/")
    )


def parse_routes() -> list[dict]:
    routes: list[dict] = []
    for file in sorted(CONTROLLERS.glob("*Controller.php")):
        source = file.read_text(encoding="utf-8")
        class_match = CLASS.search(source)
        if class_match is None:
            continue
        prefix = ""
        for attr in ATTR.finditer(source):
            path_match = PATH.search(attr.group(1))
            if path_match is None:
                raise ValueError(f"Atributo Route no legible en {file.name}")
            route_path = path_match.group(2)
            if attr.start() < class_match.start():
                prefix = route_path
                continue
            path = prefix.rstrip("/") + route_path
            if not relevant(path):
                continue
            rest = source[attr.end():]
            function = FUNCTION.search(rest)
            if function is None:
                raise ValueError(f"Ruta {path} sin método público: {file.name}")
            start = attr.end() + function.start()
            boundary = NEXT_FUNCTION.search(source, start + 15)
            body = source[start:boundary.start() if boundary else len(source)]
            method_match = METHODS.search(attr.group(1))
            methods = (
                re.findall(r"'([A-Z]+)'", method_match.group(1))
                if method_match else []
            )
            if not methods:
                raise ValueError(f"Ruta API sin métodos HTTP explícitos: {file.name} {path}")
            payload = PAYLOAD.search(body)
            route_name = re.search(r"name:\s*'([^']+)'", attr.group(1))
            permission = unique(PERMISSION.findall(body))
            if not permission:
                permission = unique(VARIABLE_PERMISSION.findall(body))
            for method in methods:
                routes.append({
                    "path": path,
                    "method": method.lower(),
                    "file": file.name,
                    "handler": function.group(1),
                    "name": route_name.group(1) if route_name else "",
                    "permissions": permission,
                    "query": unique(QUERY.findall(body)),
                    "bodyFields": FIELD.findall(payload.group(1)) if payload else [],
                    "csrf": bool(re.search(r"require(?:Management)?Csrf|assertCsrf|isCsrfTokenValid", body)),
                    "created": "HTTP_CREATED" in body,
                    "noContent": "HTTP_NO_CONTENT" in body,
                    "owner": path.startswith("/adminpl0n3r/"),
                })
    if not routes:
        raise ValueError("No se encontraron rutas API en el código")
    names: set[tuple[str, str]] = set()
    for route in routes:
        key = (route["path"], route["method"])
        if key in names:
            raise ValueError(f"Operación duplicada: {key}")
        names.add(key)
    return sorted(routes, key=lambda r: (r["path"], r["method"]))


def required_permissions(route: dict) -> list[str]:
    if route["owner"]:
        return ["ROLE_PLATFORM_OWNER"]
    if route["permissions"]:
        return route["permissions"]
    name = route["file"]
    handler = route["handler"]
    if name == "ContractsController.php":
        return [{
            "list": "inventory.view", "view": "inventory.view",
            "create": "inventory.create", "state": "inventory.update",
        }[handler]]
    if name == "ReportsController.php":
        return ["inventory.view", "orders.view"]
    if name == "ApiContextController.php":
        return ["tenant_membership_and_accessible_branch"]
    if name == "EmployeeController.php":
        return ["tenant_owner"]
    raise ValueError(f"Permiso no inferible: {name}::{handler}")


def operation(route: dict) -> dict:
    path = route["path"]
    method = route["method"]
    args = [
        {"in": "path", "name": name, "required": True, "schema": {"type": "string"}}
        for name in re.findall(r"\{(\w+)\}", path)
    ]
    args += [
        {"in": "query", "name": name, "required": False, "schema": {"type": "string"}}
        for name in route["query"]
    ]
    csrf = route["csrf"] or (
        route["file"] == "ContractsController.php" and method in {"post", "patch"}
    )
    if csrf:
        args.append({
            "in": "header", "name": "X-CSRF-Token", "required": True,
            "schema": {"type": "string"}, "description": "Token CSRF de sesión.",
        })
    result = {
        "operationId": route["name"] or route["file"].removesuffix(".php") + "_" + route["handler"],
        "summary": method.upper() + " " + path,
        "tags": ["Propietario" if route["owner"] else "Administración"],
        "security": [{"sessionAuth": []}],
        "x-controller": "src/Http/Controller/" + route["file"] + "::" + route["handler"],
        "x-required-permissions": required_permissions(route),
        "x-csrf-required": csrf,
    }
    if args:
        result["parameters"] = args
    if method in {"post", "put", "patch"}:
        schema = {"type": "object"}
        if route["bodyFields"]:
            schema["properties"] = {
                key: {"description": "Validación y tipo definidos por el controlador."}
                for key in route["bodyFields"]
            }
            schema["additionalProperties"] = False
        else:
            schema["additionalProperties"] = True
        result["requestBody"] = {
            "required": False,
            "content": {"application/json": {"schema": schema}},
            "description": "Cuerpo JSON, cuando lo admite este controlador; ver contrato y validación del código.",
        }
    code = "201" if route["created"] else "204" if route["noContent"] else "200"
    success = {"description": "Respuesta conforme a la implementación de la ruta."}
    if code != "204":
        if path.endswith(".csv"):
            success["content"] = {"text/csv": {"schema": {"type": "string"}}}
        else:
            success["content"] = {"application/json": {
                "schema": {"type": "object", "additionalProperties": True}
            }}
    responses = {code: success}
    for status in ERRORS:
        responses[status] = {"$ref": "#/components/responses/Http" + status}
    responses["500"] = {"$ref": "#/components/responses/Http500"}
    result["responses"] = responses
    return result


def build_reference() -> dict:
    paths: dict[str, dict] = {}
    for route in parse_routes():
        paths.setdefault(route["path"], {})[route["method"]] = operation(route)
    return {
        "openapi": "3.1.0",
        "info": {
            "title": "Condor — API administrativa y del propietario",
            "version": "1.0.0",
            "description": (
                "Referencia generada desde atributos #[Route] de Symfony. "
                "Los esquemas de cuerpos/respuestas son orientativos donde el "
                "contrato PHP no declara un esquema tipado. No publica endpoints."
            ),
        },
        "servers": [{"url": "/", "description": "Mismo origen autenticado."}],
        "paths": paths,
        "components": {
            "responses": {
                **{
                    "Http" + status: {
                        "description": error,
                        "content": {"application/json": {
                            "schema": {"$ref": "#/components/schemas/ApiError"}
                        }},
                    }
                    for status, error in ERRORS.items()
                },
                "Http500": {
                    "description": "internal_error",
                    "content": {"application/json": {
                        "schema": {"$ref": "#/components/schemas/InternalError"}
                    }},
                },
            },
            "securitySchemes": {
                "sessionAuth": {
                    "type": "apiKey", "in": "cookie", "name": "PHPSESSID",
                    "description": "Sesión Symfony; el nombre efectivo depende de la configuración.",
                }
            },
            "schemas": {
                "ApiError": {
                    "type": "object",
                    "required": ["error", "message", "status", "request_id"],
                    "properties": {
                        "error": {"type": "string"},
                        "message": {"type": "string"},
                        "status": {"type": "integer"},
                        "request_id": {"type": "string"},
                        "details": {"type": "object", "additionalProperties": True},
                    },
                },
                "InternalError": {
                    "type": "object",
                    "required": ["error", "message", "error_id"],
                    "properties": {
                        "error": {"type": "string"},
                        "message": {"type": "string"},
                        "error_id": {"type": "string"},
                    },
                },
            },
        },
    }


def rendered() -> str:
    return json.dumps(build_reference(), ensure_ascii=False, sort_keys=True, indent=2) + "\n"


class OpenApiCondorReferenciaTests(unittest.TestCase):
    def test_contrato_openapi_y_cobertura_completa(self) -> None:
        actual = json.loads(REFERENCE.read_text(encoding="utf-8"))
        expected = build_reference()
        self.assertEqual(expected, actual, "OpenAPI desactualizado: regenerar con --write")
        self.assertEqual("3.1.0", actual["openapi"])
        routes = parse_routes()
        self.assertGreaterEqual(len(routes), 183)
        self.assertTrue(any(r["owner"] for r in routes))
        self.assertTrue(any(not r["owner"] for r in routes))
        for route in routes:
            op = actual["paths"][route["path"]][route["method"]]
            self.assertTrue(op["x-required-permissions"])
            self.assertIn("403", op["responses"])
            self.assertIn("401", op["responses"])
            for variable in re.findall(r"\{(\w+)\}", route["path"]):
                self.assertTrue(any(
                    p["in"] == "path" and p["name"] == variable and p["required"]
                    for p in op.get("parameters", [])
                ))

    def test_regeneracion_determinista_y_serie_de_errores(self) -> None:
        self.assertEqual(rendered(), rendered())
        self.assertEqual(rendered(), REFERENCE.read_text(encoding="utf-8"))
        spec = build_reference()
        for operations in spec["paths"].values():
            for op in operations.values():
                self.assertTrue({"400", "401", "403", "404", "409", "422", "429", "500"} <= set(op["responses"]))
                self.assertEqual([{"sessionAuth": []}], op["security"])

    def test_detecta_deriva_de_rutas_y_metodos(self) -> None:
        actual = json.loads(REFERENCE.read_text(encoding="utf-8"))
        routes = parse_routes()
        first = routes[0]
        del actual["paths"][first["path"]][first["method"]]
        self.assertNotEqual(actual, build_reference())


if __name__ == "__main__":
    if len(sys.argv) == 2 and sys.argv[1] == "--write":
        output = rendered()
        REFERENCE.parent.mkdir(parents=True, exist_ok=True)
        if not REFERENCE.exists() or REFERENCE.read_text(encoding="utf-8") != output:
            REFERENCE.write_text(output, encoding="utf-8")
        print(f"OpenAPI generado: {REFERENCE}")
    elif len(sys.argv) == 2 and sys.argv[1] == "--check":
        if not REFERENCE.is_file() or REFERENCE.read_text(encoding="utf-8") != rendered():
            raise SystemExit("OpenAPI desactualizado: ejecutar --write")
        print("OpenAPI reproducible y actualizado")
    else:
        unittest.main()
