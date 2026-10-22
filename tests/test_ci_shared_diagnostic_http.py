"""CI discovery bridge for Condor #775 HTTP acceptance.

The contracts job discovers test_ci_*.py only. Without vendor, test_acceptance
runs real controller/service/sanitizer with bounded transport/ORM doubles.
The separate Symfony/Doctrine WebTestCase is reported as skipped unless
vendor/bin/simple-phpunit exists; this is not production or E2E evidence.
"""

from test_shared_diagnostic_http import DiagnosticoCompartidoHttpTests

__all__ = ["DiagnosticoCompartidoHttpTests"]
