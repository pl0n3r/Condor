#!/usr/bin/env python3
"""Pruebas unitarias del coordinador multiagente de Condor."""

from __future__ import annotations

import unittest
from pathlib import Path

from scripts.coordinar_trabajo import (
    CoordinationError,
    GitHub,
    GitHubError,
    STATUS_AVAILABLE,
    STATUS_BLOCKED,
    STATUS_COMPLETED,
    STATUS_RESERVED,
    STATUS_REVIEW,
    active_reservation,
    authorized,
    closing_issues,
    file_overlaps,
    issue_from_branch,
    latest_reservation,
    parse_comment_command,
    release_work,
    reservation_from_pr_body,
    reservation_marker,
    reserve_work,
    transfer_work,
    update_issue_label_state,
    update_issue_state,
    update_pr_state,
    validate_pull,
)


BOT = "github-actions[bot]"
SESSION_A = "11111111-1111-4111-8111-111111111111"
SESSION_B = "22222222-2222-4222-8222-222222222222"


class FakeGitHub:
    """Simula únicamente las operaciones de GitHub usadas por el coordinador."""

    def __init__(self) -> None:
        """Crea un repositorio falso con un Issue disponible."""
        self.repo = "pl0n3r/Condor"
        self.branches = {"main": "abc123"}
        self.issue_data = {
            "number": 12,
            "state": "open",
            "state_reason": None,
            "labels": [{"name": STATUS_AVAILABLE}],
        }
        self.comments: list[dict] = []
        self.pulls: dict[int, dict] = {}
        self.pull_files_map: dict[int, set[str]] = {}
        self.status_history: list[str | None] = []
        self.assignees: set[str] = set()
        self.fail_comment = False

    def issue(self, number: int) -> dict:
        """Devuelve el Issue falso."""
        assert number == 12
        return self.issue_data

    def pull(self, number: int) -> dict:
        """Devuelve un PR falso."""
        return self.pulls[number]

    def comment(self, issue_number: int, body: str) -> None:
        """Publica un comentario confiable del bot."""
        assert issue_number == 12
        if self.fail_comment:
            raise CoordinationError("fallo simulado de comentario")
        self.comments.append({"body": body, "user": {"login": BOT}})

    def set_status(self, issue_number: int, status: str | None) -> None:
        """Reemplaza el estado visible del Issue."""
        assert issue_number == 12
        current = [
            item
            for item in self.issue_data["labels"]
            if not str(item["name"]).startswith("estado: ")
        ]
        if status:
            current.append({"name": status})
        self.issue_data["labels"] = current
        self.status_history.append(status)

    def branch_sha(self, branch: str) -> str | None:
        """Devuelve el SHA de una rama falsa."""
        return self.branches.get(branch)

    def create_branch(self, branch: str, sha: str) -> bool:
        """Crea una rama si no existe."""
        if branch in self.branches:
            return False
        self.branches[branch] = sha
        return True

    def delete_branch(self, branch: str) -> None:
        """Elimina una rama si existe."""
        self.branches.pop(branch, None)

    def issue_comments(self, issue_number: int) -> list[dict]:
        """Devuelve comentarios del Issue."""
        assert issue_number == 12
        return list(self.comments)

    def open_pulls(self) -> list[dict]:
        """Devuelve los PR falsos abiertos."""
        return [
            pull
            for pull in self.pulls.values()
            if pull.get("state", "open") == "open"
        ]

    def pull_files(self, number: int) -> set[str]:
        """Devuelve archivos de un PR falso."""
        return set(self.pull_files_map.get(number, set()))

    def close_pull(self, number: int) -> None:
        """Cierra un PR falso."""
        self.pulls[number]["state"] = "closed"

    def try_assign(self, issue_number: int, login: str) -> None:
        """Asigna el Issue."""
        assert issue_number == 12
        self.assignees.add(login)

    def try_unassign(self, issue_number: int, login: str) -> None:
        """Retira la asignación del Issue."""
        assert issue_number == 12
        self.assignees.discard(login)


def add_active_reservation(
    api: FakeGitHub,
    owner: str = "pl0n3r",
    reservation_id: str = SESSION_A,
) -> None:
    """Inserta una reserva confiable activa en el fake."""
    branch = "trabajo/issue-12"
    api.branches[branch] = "abc123"
    api.set_status(12, STATUS_RESERVED)
    api.comments.append(
        {
            "user": {"login": BOT},
            "body": reservation_marker(
                owner,
                reservation_id,
                branch,
                True,
                "tomar",
            ),
        }
    )



class LimpiezaRamaTests(unittest.TestCase):
    """Cubre idempotencia y fail-closed al limpiar referencias Git."""

    def test_delete_branch_existing_succeeds_without_extra_lookup(self) -> None:
        """Una eliminación normal no realiza comprobaciones innecesarias."""
        api = GitHub("pl0n3r/Condor", "token-prueba")
        calls: list[str] = []

        def request(method, path, payload=None, allow=()):
            calls.append(method)
            return None

        api.request = request  # type: ignore[method-assign]
        api.delete_branch("trabajo/issue-19")
        self.assertEqual(calls, ["DELETE"])

    def test_delete_branch_ignores_422_only_when_reference_is_gone(self) -> None:
        """Un 422 por referencia ya eliminada se trata como éxito idempotente."""
        api = GitHub("pl0n3r/Condor", "token-prueba")

        def request(method, path, payload=None, allow=()):
            if method == "DELETE":
                raise GitHubError(422, "Reference does not exist")
            return None

        api.request = request  # type: ignore[method-assign]
        api.delete_branch("trabajo/issue-19")

    def test_delete_branch_keeps_422_when_reference_still_exists(self) -> None:
        """Un 422 real no se oculta si GitHub confirma que la rama existe."""
        api = GitHub("pl0n3r/Condor", "token-prueba")

        def request(method, path, payload=None, allow=()):
            if method == "DELETE":
                raise GitHubError(422, "Validation Failed")
            return {"object": {"sha": "abc123"}}

        api.request = request  # type: ignore[method-assign]
        with self.assertRaises(GitHubError):
            api.delete_branch("trabajo/issue-19")


class WorkflowCoordinacionTests(unittest.TestCase):
    """Cubre el cableado declarativo entre GitHub Events y el coordinador."""

    def test_issue_comment_routes_only_supported_commands_to_coordinator(self) -> None:
        """El workflow escucha comentarios y delega únicamente comandos soportados."""
        workflow = (
            Path(__file__).resolve().parents[1]
            / ".github"
            / "workflows"
            / "coordinacion-trabajo.yml"
        ).read_text(encoding="utf-8")

        self.assertIn("issue_comment:", workflow)
        self.assertIn("types: [created, edited]", workflow)
        self.assertIn("github.event.issue.pull_request == null", workflow)
        self.assertIn("github.event.comment.body == '/tomar'", workflow)
        self.assertIn("github.event.comment.body == '/liberar-forzado'", workflow)
        self.assertIn("startsWith(github.event.comment.body, '/liberar ')", workflow)
        self.assertIn("startsWith(github.event.comment.body, '/transferir ')", workflow)
        self.assertIn("github.event.comment.author_association", workflow)
        self.assertIn("python3 scripts/coordinar_trabajo.py comentario", workflow)
        self.assertIn('--body "$CUERPO"', workflow)



class EstadoCoordinacionTests(unittest.TestCase):
    """Cubre transiciones de estado visibles sin ventanas intermedias inválidas."""

    def test_set_status_adds_target_before_removing_previous_labels(self) -> None:
        """El nuevo estado se publica antes de retirar estados anteriores."""
        api = GitHub("pl0n3r/Condor", "token-prueba")
        events: list[tuple[str, str]] = []

        api.ensure_status_labels = lambda: None  # type: ignore[method-assign]
        api.add_labels = (  # type: ignore[method-assign]
            lambda issue_number, labels: events.append(("add", labels[0]))
        )
        api.remove_label = (  # type: ignore[method-assign]
            lambda issue_number, label: events.append(("remove", label))
        )

        api.set_status(12, STATUS_REVIEW)

        self.assertEqual(events[0], ("add", STATUS_REVIEW))
        self.assertNotIn(("remove", STATUS_REVIEW), events)
        self.assertIn(("remove", STATUS_RESERVED), events)
        self.assertIn(("remove", STATUS_AVAILABLE), events)


class CoordinacionTests(unittest.TestCase):
    """Cubre contratos de reserva, sesión, transición y colisiones."""

    def test_issue_from_branch(self) -> None:
        """Extrae Issue únicamente de ramas canónicas."""
        self.assertEqual(issue_from_branch("trabajo/issue-12"), 12)
        self.assertEqual(issue_from_branch("trabajo/issue-999"), 999)
        self.assertIsNone(issue_from_branch("feature/algo"))
        self.assertIsNone(issue_from_branch("trabajo/issue-x"))

    def test_closing_issues(self) -> None:
        """Reconoce las referencias de cierre admitidas."""
        body = "Closes #12\nFixes #18\nresolves #21"
        self.assertEqual(closing_issues(body), {12, 18, 21})

    def test_reservation_from_pr_body(self) -> None:
        """Extrae el ID de sesión visible legacy u oculto."""
        self.assertEqual(
            reservation_from_pr_body(f"Closes #12\nReserva: {SESSION_A}"),
            SESSION_A,
        )
        self.assertEqual(
            reservation_from_pr_body(
                f"Closes #12\n<!-- condor-reserva-id: {SESSION_A} -->"
            ),
            SESSION_A,
        )
        self.assertIsNone(reservation_from_pr_body("Closes #12"))

    def test_authorized_associations(self) -> None:
        """Limita comandos a colaboradores reales."""
        self.assertTrue(authorized("OWNER"))
        self.assertTrue(authorized("MEMBER"))
        self.assertTrue(authorized("COLLABORATOR"))
        self.assertFalse(authorized("NONE"))
        self.assertFalse(authorized("CONTRIBUTOR"))

    def test_markers_from_untrusted_users_are_ignored(self) -> None:
        """Ignora marcadores falsificados por comentarios externos."""
        marker = reservation_marker(
            "intruso",
            SESSION_A,
            "trabajo/issue-12",
            False,
            "falso",
        )
        comments = [{"body": marker, "user": {"login": "intruso"}}]
        self.assertIsNone(latest_reservation(comments))

    def test_issue_body_marker_is_not_trusted(self) -> None:
        """Metadata editable del body no autentica una reserva."""
        api = FakeGitHub()
        api.issue_data["body"] = reservation_marker(
            "intruso",
            SESSION_B,
            "trabajo/issue-12",
            True,
            "falso",
        )

        self.assertIsNone(active_reservation(api, 12))

    def test_latest_reservation_prefers_last_trusted_marker(self) -> None:
        """Usa el último marcador válido publicado por el bot."""
        first = reservation_marker(
            "agente-a",
            SESSION_A,
            "trabajo/issue-12",
            True,
            "tomar",
        )
        second = reservation_marker(
            "agente-a",
            SESSION_A,
            "trabajo/issue-12",
            False,
            "liberar",
        )
        comments = [
            {"body": first, "user": {"login": BOT}},
            {"body": second, "user": {"login": BOT}},
        ]
        latest = latest_reservation(comments)
        self.assertIsNotNone(latest)
        assert latest is not None
        self.assertFalse(latest["active"])

    def test_reserve_work_creates_atomic_lock_and_session(self) -> None:
        """Una toma exitosa crea rama, estado y sesión única."""
        api = FakeGitHub()
        session = reserve_work(api, 12, "pl0n3r", "OWNER")
        self.assertIsNotNone(session)
        self.assertIn("trabajo/issue-12", api.branches)
        self.assertIn("pl0n3r", api.assignees)
        self.assertEqual(api.status_history[-1], STATUS_RESERVED)
        reservation = active_reservation(api, 12)
        self.assertIsNotNone(reservation)
        assert reservation is not None
        self.assertEqual(reservation["reservation_id"], session)
        self.assertEqual(len(api.comments), 1)
        self.assertTrue(api.comments[0]["body"].startswith("<!-- condor-reserva "))
        self.assertNotIn("Trabajo reservado", api.comments[0]["body"])

    def test_label_reserved_creates_silent_reservation(self) -> None:
        """El label reservado crea el lock sin comentarios visibles."""
        api = FakeGitHub()
        api.issue_data["labels"].append({"name": STATUS_RESERVED})

        update_issue_label_state(api, 12, "pl0n3r", STATUS_RESERVED)

        self.assertIn("trabajo/issue-12", api.branches)
        self.assertEqual(len(api.comments), 1)
        self.assertTrue(api.comments[0]["body"].startswith("<!-- condor-reserva "))
        reservation = active_reservation(api, 12)
        self.assertIsNotNone(reservation)

    def test_label_reserved_restores_blocked_state_if_rejected(self) -> None:
        """Un intento inválido no deja un falso estado reservado."""
        api = FakeGitHub()
        api.issue_data["labels"] = [
            {"name": STATUS_BLOCKED},
            {"name": STATUS_RESERVED},
        ]

        update_issue_label_state(api, 12, "pl0n3r", STATUS_RESERVED)

        self.assertNotIn("trabajo/issue-12", api.branches)
        self.assertEqual(api.status_history[-1], STATUS_BLOCKED)

    def test_label_reserved_keeps_concurrent_winner_reserved(self) -> None:
        """Una rama ganadora evita que el perdedor restaure disponible."""
        api = FakeGitHub()
        api.issue_data["labels"].append({"name": STATUS_RESERVED})
        api.branches["trabajo/issue-12"] = "winner-sha"

        update_issue_label_state(api, 12, "pl0n3r", STATUS_RESERVED)

        self.assertEqual(api.status_history[-1], STATUS_RESERVED)

    def test_label_available_cannot_release_another_session(self) -> None:
        """El label disponible nunca recibe autoridad de sesión implícita."""
        api = FakeGitHub()
        add_active_reservation(api)
        api.issue_data["labels"].append({"name": STATUS_AVAILABLE})

        update_issue_label_state(api, 12, "pl0n3r", STATUS_AVAILABLE)

        self.assertIn("trabajo/issue-12", api.branches)
        reservation = active_reservation(api, 12)
        self.assertIsNotNone(reservation)
        self.assertEqual(reservation["reservation_id"], SESSION_A)

    def test_second_reservation_cannot_win_same_branch(self) -> None:
        """Una rama existente impide una segunda reserva."""
        api = FakeGitHub()
        api.branches["trabajo/issue-12"] = "abc123"
        result = reserve_work(api, 12, "pl0n3r", "OWNER")
        self.assertIsNone(result)
        self.assertEqual(api.status_history, [])
        self.assertIsNone(active_reservation(api, 12))

    def test_blocked_issue_cannot_be_reserved(self) -> None:
        """Un Issue bloqueado no entra a la cola de trabajo."""
        api = FakeGitHub()
        api.issue_data["labels"] = [{"name": STATUS_BLOCKED}]
        result = reserve_work(api, 12, "pl0n3r", "OWNER")
        self.assertIsNone(result)
        self.assertNotIn("trabajo/issue-12", api.branches)

    def test_reservation_rolls_back_if_marker_fails(self) -> None:
        """Un fallo al persistir metadata confiable revierte el lock."""
        api = FakeGitHub()
        api.fail_comment = True
        with self.assertRaises(CoordinationError):
            reserve_work(api, 12, "pl0n3r", "OWNER")
        self.assertNotIn("trabajo/issue-12", api.branches)
        self.assertEqual(api.status_history[-1], STATUS_AVAILABLE)

    def test_wrong_session_cannot_release(self) -> None:
        """Una sesión distinta no puede liberar la reserva."""
        api = FakeGitHub()
        add_active_reservation(api)
        release_work(api, 12, "pl0n3r", "OWNER", SESSION_B, False)
        self.assertIn("trabajo/issue-12", api.branches)
        self.assertIsNotNone(active_reservation(api, 12))

    def test_release_marks_inactive_before_available(self) -> None:
        """La liberación deja marcador inactivo y luego expone el Issue."""
        api = FakeGitHub()
        add_active_reservation(api)
        release_work(api, 12, "pl0n3r", "OWNER", SESSION_A, False)
        self.assertNotIn("trabajo/issue-12", api.branches)
        latest = latest_reservation(api.comments)
        self.assertIsNotNone(latest)
        assert latest is not None
        self.assertFalse(latest["active"])
        self.assertEqual(api.status_history[-1], STATUS_AVAILABLE)

    def test_transfer_invalidates_previous_session(self) -> None:
        """Transferir genera un ID nuevo y vuelve inválido el anterior."""
        api = FakeGitHub()
        add_active_reservation(api)
        new_id = transfer_work(api, 12, "pl0n3r", "OWNER", SESSION_A)
        self.assertIsNotNone(new_id)
        self.assertNotEqual(new_id, SESSION_A)
        reservation = active_reservation(api, 12)
        assert reservation is not None
        self.assertEqual(reservation["reservation_id"], new_id)

    def test_parse_comment_commands(self) -> None:
        """Interpreta comandos y valida UUID cuando corresponde."""
        self.assertEqual(parse_comment_command("/tomar"), ("tomar", None))
        self.assertEqual(
            parse_comment_command(f"/liberar {SESSION_A}"),
            ("liberar", SESSION_A),
        )
        self.assertEqual(
            parse_comment_command(f"/transferir {SESSION_A}"),
            ("transferir", SESSION_A),
        )
        with self.assertRaises(CoordinationError):
            parse_comment_command("/liberar no-es-uuid")

    def test_pr_opened_ready_moves_to_review(self) -> None:
        """Un PR no draft abierto desde reserva pasa a revisión."""
        api = FakeGitHub()
        add_active_reservation(api)
        api.pulls[15] = {
            "number": 15,
            "state": "open",
            "draft": False,
            "head": {"ref": "trabajo/issue-12"},
            "base": {"ref": "main"},
            "merged": False,
        }
        update_pr_state(api, 15, "opened")
        self.assertEqual(api.status_history[-1], STATUS_REVIEW)

    def test_pr_close_without_merge_releases(self) -> None:
        """Cerrar un PR sin merge libera el trabajo."""
        api = FakeGitHub()
        add_active_reservation(api)
        api.pulls[15] = {
            "number": 15,
            "state": "closed",
            "draft": False,
            "head": {"ref": "trabajo/issue-12"},
            "base": {"ref": "main"},
            "merged": False,
        }
        update_pr_state(api, 15, "closed")
        self.assertNotIn("trabajo/issue-12", api.branches)
        self.assertEqual(api.status_history[-1], STATUS_AVAILABLE)

    def test_issue_close_cleans_branch_and_completes(self) -> None:
        """Cerrar un Issue limpia su rama y marca el trabajo completado."""
        api = FakeGitHub()
        add_active_reservation(api)
        api.issue_data["state"] = "closed"
        update_issue_state(api, 12, "closed")
        self.assertNotIn("trabajo/issue-12", api.branches)
        self.assertEqual(api.status_history[-1], STATUS_COMPLETED)

    def test_validate_pull_requires_main(self) -> None:
        """Rechaza un PR cuyo destino no sea main."""
        api = FakeGitHub()
        api.pulls[15] = {
            "number": 15,
            "state": "open",
            "draft": False,
            "body": "Closes #12",
            "head": {"ref": "trabajo/issue-12"},
            "base": {"ref": "otra-rama"},
        }
        with self.assertRaises(CoordinationError):
            validate_pull(api, 15, False)

    def test_validate_pull_checks_active_session(self) -> None:
        """Valida que el PR declare exactamente la sesión activa."""
        api = FakeGitHub()
        add_active_reservation(api)
        api.pulls[15] = {
            "number": 15,
            "state": "open",
            "draft": False,
            "body": f"Closes #12\n<!-- condor-reserva-id: {SESSION_A} -->",
            "head": {"ref": "trabajo/issue-12"},
            "base": {"ref": "main"},
        }
        api.pull_files_map[15] = {"src/a.php"}
        validate_pull(api, 15, True)

        api.pulls[15]["body"] = (
            f"Closes #12\n<!-- condor-reserva-id: {SESSION_B} -->"
        )
        with self.assertRaises(CoordinationError):
            validate_pull(api, 15, True)

    def test_file_overlap_detects_collisions(self) -> None:
        """Detecta colisiones exactas de archivos."""
        current = {"src/a.php", "README.md", "src/b.php"}
        others = {
            10: {"src/a.php", "src/x.php"},
            11: {"docs/otro.md"},
            12: {"README.md"},
        }
        self.assertEqual(
            file_overlaps(current, others),
            {10: ["src/a.php"], 12: ["README.md"]},
        )

    def test_validate_pull_rejects_open_pr_overlap(self) -> None:
        """Rechaza un PR que pisa archivos de otro PR abierto."""
        api = FakeGitHub()
        add_active_reservation(api)
        api.pulls[15] = {
            "number": 15,
            "state": "open",
            "draft": False,
            "body": f"Closes #12\n<!-- condor-reserva-id: {SESSION_A} -->",
            "head": {"ref": "trabajo/issue-12"},
            "base": {"ref": "main"},
        }
        api.pulls[20] = {
            "number": 20,
            "state": "open",
            "draft": False,
            "body": "Closes #20",
            "head": {"ref": "trabajo/issue-20"},
            "base": {"ref": "main"},
        }
        api.pull_files_map[15] = {"src/a.php"}
        api.pull_files_map[20] = {"src/a.php"}
        with self.assertRaises(CoordinationError):
            validate_pull(api, 15, True)


if __name__ == "__main__":
    unittest.main()
