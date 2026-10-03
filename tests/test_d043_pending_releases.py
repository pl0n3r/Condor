"""Pruebas del reductor D-043 de comentarios de roadmap."""

from __future__ import annotations

import importlib.util
import json
import sys
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "d043_pending_releases.py"
spec = importlib.util.spec_from_file_location("d043_pending_releases", SCRIPT)
assert spec is not None
assert spec.loader is not None
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)

SHA_122 = "1" * 40
SHA_123 = "2" * 40
SHA_124 = "3" * 40


def comment(login: str, body: str) -> dict:
    return {"user": {"login": login}, "body": body}


def deploy(version: str, sha: str) -> str:
    return (
        "🚧 DEPLOY_OBSERVED automático: producción sirve "
        f"V {version} ({sha}), pero no se declara validada."
    )


def validated(version: str, sha: str) -> str:
    return (
        "✅ VALIDATED_IN_PRODUCTION automático: producción sirve "
        f"V {version} ({sha}) y los smoke checks de solo lectura pasaron."
    )


class D043PendingReleasesTests(unittest.TestCase):
    def test_reducer_returns_only_exact_unvalidated_bot_release_identities(self) -> None:
        comments = [
            comment("pl0n3r", deploy("0.1.121", "0" * 40)),
            comment("github-actions[bot]", deploy("0.1.122", SHA_122)),
            comment("github-actions[bot]", validated("0.1.123", SHA_123)),
            comment(
                "github-actions[bot]",
                '<!-- condor-d043-validation-card {"sha":"' + SHA_124 + '"} -->',
            ),
            comment("github-actions[bot]", deploy("0.1.124", SHA_124)),
        ]

        self.assertEqual(
            module.reduce_pending_releases(comments),
            [
                {"version": "0.1.122", "sha": SHA_122},
                {"version": "0.1.124", "sha": SHA_124},
            ],
        )

    def test_newer_validation_does_not_cover_older_pending_release(self) -> None:
        comments = [
            comment("github-actions[bot]", deploy("0.1.122", SHA_122)),
            comment("github-actions[bot]", deploy("0.1.123", SHA_123)),
            comment("github-actions[bot]", validated("0.1.123", SHA_123)),
        ]

        self.assertEqual(
            module.reduce_pending_releases(comments),
            [{"version": "0.1.122", "sha": SHA_122}],
        )

    def test_duplicate_events_are_idempotent_and_output_is_stably_ordered(self) -> None:
        pages = [
            [
                comment("github-actions[bot]", deploy("0.1.124", SHA_124)),
                comment("github-actions[bot]", deploy("0.1.122", SHA_122)),
                comment("github-actions[bot]", deploy("0.1.122", SHA_122)),
            ],
            [
                comment("github-actions[bot]", validated("0.1.124", SHA_124)),
                comment("github-actions[bot]", validated("0.1.124", SHA_124)),
                comment("github-actions[bot]", deploy("0.1.124", SHA_124)),
            ],
        ]

        flattened = module.flatten_comments(pages)
        self.assertEqual(
            module.reduce_pending_releases(flattened),
            [{"version": "0.1.122", "sha": SHA_122}],
        )

    def test_conflicting_version_or_sha_identity_fails_closed(self) -> None:
        with self.subTest("same version different sha"):
            with self.assertRaises(module.PendingReleaseError):
                module.reduce_pending_releases([
                    comment("github-actions[bot]", deploy("0.1.122", SHA_122)),
                    comment("github-actions[bot]", deploy("0.1.122", SHA_123)),
                ])

        with self.subTest("same sha different version"):
            with self.assertRaises(module.PendingReleaseError):
                module.reduce_pending_releases([
                    comment("github-actions[bot]", deploy("0.1.122", SHA_122)),
                    comment("github-actions[bot]", deploy("0.1.123", SHA_122)),
                ])

    def test_human_cards_malformed_text_and_secrets_are_ignored(self) -> None:
        secret = "password=do-not-copy"
        comments = [
            comment("pl0n3r", deploy("0.1.122", SHA_122) + " " + secret),
            comment(
                "github-actions[bot]",
                '<!-- condor-d043-validation-card {"sha":"' + SHA_124 +
                '"} --> token=do-not-copy',
            ),
            comment(
                "github-actions[bot]",
                "🚧 DEPLOY_OBSERVED automático: producción sirve V 0.1.124 "
                "(not-a-sha), pero no se declara validada.",
            ),
            comment("github-actions[bot]", "texto libre " + secret),
        ]

        result = module.reduce_pending_releases(comments)
        self.assertEqual(result, [])
        serialized = json.dumps(result).lower()
        self.assertNotIn("password", serialized)
        self.assertNotIn("token", serialized)
        self.assertNotIn("secret", serialized)


if __name__ == "__main__":
    unittest.main()
