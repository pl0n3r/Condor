"""Bridge real-PHP F2a acceptance into the canonical CI test_ci_*.py discovery.

This module re-exports the same four tests, without mocks, skips, or
changing the application's compliance projection.
"""

from test_compliance_attention_projection import ComplianceAttentionProjectionTests

__all__ = ["ComplianceAttentionProjectionTests"]
