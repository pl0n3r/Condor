"""Puente CI de #1027: carga los cuatro AC del contraste sin duplicarlos.

El workflow canónico ejecuta unittest discover -s tests -p 'test_ci_*.py'.
El import conserva las aserciones originales y solo inspecciona documentos de
la rama con datos sintéticos; no abre conexiones a servicios ni producción.
"""
from test_contraste_cd_d3 import ContrasteTests as ContrasteTests
