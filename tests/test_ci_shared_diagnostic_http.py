"""CI discovery bridge for Condor #775 HTTP acceptance.

The contracts job discovers test_ci_*.py only. Import the exact real
Symfony/Doctrine WebTestCase acceptance, without skips, mocks or substitutes.
"""

from test_shared_diagnostic_http import DiagnosticoCompartidoHttpTests

__all__ = ["DiagnosticoCompartidoHttpTests"]
