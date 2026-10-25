"""Ensure the #854 exact acceptance test is executed by CI contract discovery."""
import importlib.util
import unittest
from pathlib import Path


def load_tests(loader, tests, pattern):
    source = Path(__file__).resolve().parents[1] / 'test_restock_suggestions.py'
    spec = importlib.util.spec_from_file_location('restock_suggestions_acceptance', source)
    if spec is None or spec.loader is None:
        raise RuntimeError('Restock acceptance missing')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return loader.loadTestsFromTestCase(module.ResurtidoSugerenciasTests)
