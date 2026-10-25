"""Puente de descubrimiento CI para AC-01 de Google Tag Manager (#867).

El CI usa `python3 -m unittest discover -s tests -p 'test_ci_*.py'`.
Reexportar la clase mantiene una sola suite de aceptación ejecutable.
"""
from test_tag_manager_settings import GtmConfigTenantTests

__all__ = ["GtmConfigTenantTests"]
