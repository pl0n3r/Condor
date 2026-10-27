"""Descubrimiento de la suite de soporte #997 por el patrón test_ci_*.py de pruebas-base.

Importar la clase original expone los mismos métodos a unittest discovery;
no se duplican aserciones ni se ejecuta una suite distinta.
"""

from test_salida_soporte_operacion import SalidaTests

__all__ = ["SalidaTests"]
