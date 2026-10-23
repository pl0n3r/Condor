"""CI discovery for #790: execute the existing minors-data acceptance TestCase.

The canonical workflow discovers test_ci_*.py. Import the original
LegalMenoresTests without duplicating or skipping the AC-01 assertions.
"""
from test_legal_minors_rule import LegalMenoresTests

__all__ = ["LegalMenoresTests"]
