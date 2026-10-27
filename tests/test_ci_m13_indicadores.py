"""Expose real PHP M13 acceptance to Condor's canonical test_ci_*.py discovery.

No mocks, skips or alternative implementation.
"""

from test_m13_indicadores import QualityIndicatorsEfficacyTests

__all__ = ["QualityIndicatorsEfficacyTests"]
