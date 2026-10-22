"""CI discovery of Condor #789's unsubscribed-mail acceptance tests.

Keep the original contract target in test_legal_unsubscribe_contract.py;
the CI step only discovers test_ci_*.py.
"""
from test_legal_unsubscribe_contract import LegalBajaComunicacionesTests  # noqa: F401
