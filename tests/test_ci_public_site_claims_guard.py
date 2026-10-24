"""Bridge CI para el contrato AC-01 de Condor #791.

El workflow descubre test_ci_*.py. Importa el TestCase original de la
protección de afirmaciones públicas, sin skips ni pruebas duplicadas.
"""
from test_public_site_claims_guard import AfirmacionesSitioPublicoTests

__all__ = ["AfirmacionesSitioPublicoTests"]
