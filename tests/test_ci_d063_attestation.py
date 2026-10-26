"""CI discovery: ejecutar AC-01 de Condor #944 sin duplicar la suite.

El job de integración descubre test_ci_*.py. Importar la clase real
asegura los mismos casos positivos y negativos que el AC nominado.
"""
from test_d063_attestation import D063AttestationTests

__all__ = ["D063AttestationTests"]
