"""Puente CI #1059: ejecutar la aceptación M16 por descubrimiento oficial.

.github/workflows/ci.yml usa unittest discover con el patrón test_ci_*.py.
Importar ModuloTests hace que unittest ejecute los cuatro tests canónicos
de tests/test_customer_service_persistence.py, sin duplicar sus aserciones.
Solo se usan fixtures opacas; nunca se conecta a servicios ni datos reales.
"""

from test_customer_service_persistence import ModuloTests as ModuloTests
