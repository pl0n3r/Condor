"""Descubrimiento de AC-01 y regresiones de Condor #786 en CI.

El job Pruebas de contrato e integración descubre solo test_ci_*.py.
Reexportar la clase original evita doble lógica, mocks y omisiones.
No valida aprobación jurídica ni producción.
"""

from test_legal_refund_policy_doc import LegalReembolsosTests

__all__ = ["LegalReembolsosTests"]
