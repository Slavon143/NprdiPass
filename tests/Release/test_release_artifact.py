import hashlib
import importlib.util
import io
import json
import os
import stat
import subprocess
import sys
from pathlib import Path
import tarfile
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location("release_artifact", Path(__file__).parents[2] / "deploy/scripts/release_artifact.py")
release = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(release)


class ReleaseArtifactTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.source = self.root / "source"
        inputs = {"artisan": "cli", "app/Rule.php": "<?php return true;", "composer.lock": "{}",
                  "vendor/autoload.php": "<?php", "public/build/manifest.json": "{}",
                  "resources/views/view.blade.php": "view", "bootstrap/app.php": "<?php",
                  "lang/en.json": '{"Sign in":"Sign in"}', "lang/sv/auth.php": "<?php return ['failed' => 'Fel'];",
                  ".env": "secret", ".env.production": "secret", "storage/app/customer.xlsx": "private",
                  "storage/app/private/backups/database.sql": "private", "storage/logs/app.log": "private",
                  "bootstrap/cache/config.php": "STRIPE_SECRET=private", "public/hot": "local-dev",
                  "tests/private.php": "private", "auth.json": "composer-secret"}
        for name, content in inputs.items():
            path = self.source / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content)
        self.artifact = self.root / "release.tar.gz"
        self.checksum = Path(str(self.artifact) + ".sha256")

    def build(self):
        return release.build(self.source, self.artifact, "a" * 40, "v1", "8.4.26", "22.20.0", "2026-10-03T12:00:00Z")

    def rewrite_manifest(self, changes):
        with tarfile.open(self.artifact) as archive:
            entries = [(entry, archive.extractfile(entry).read() if entry.isfile() else None) for entry in archive]
        with tarfile.open(self.artifact, 'w:gz') as archive:
            for entry, content in entries:
                if entry.name == release.ROOT + '/RELEASE.json':
                    manifest = json.loads(content)
                    manifest.update(changes)
                    content = release.canonical(manifest)
                    entry.size = len(content)
                archive.addfile(entry, io.BytesIO(content) if content is not None else None)
        self.checksum.write_text(release.digest(self.artifact) + '  ' + self.artifact.name + '\n')

    def test_build_records_exact_observed_node_version(self):
        manifest = release.build(self.source, self.artifact, 'a'*40, 'v1', '8.4.26', '22.21.1', '2026-10-11T00:00:00Z')
        self.assertEqual(manifest['format_version'], 2)
        self.assertEqual(release.verify(self.artifact, self.checksum)[0]['node_version'], '22.21.1')

    def test_unknown_or_unsupported_node_version_refuses_build_before_artifact_creation(self):
        for version in ('22.x', '', 'v22.20.0', '24.15.0', '22.20.0;echo unsafe'):
            with self.subTest(version=version), self.assertRaisesRegex(ValueError, 'exact observed Node 22'):
                release.build(self.source, self.artifact, 'a'*40, 'v1', '8.4.26', version, '2026-10-11T00:00:00Z')
            self.assertFalse(self.artifact.exists())

    def test_legacy_archive_stays_verifiable_without_rewriting_historical_provenance(self):
        self.build()
        self.rewrite_manifest({'format_version': 1, 'node_version': '22.x'})
        manifest, _ = release.verify(self.artifact, self.checksum)
        self.assertEqual(manifest['format_version'], 1)
        self.assertEqual(manifest['node_version'], '22.x')

    def test_new_manifest_cannot_claim_wildcard_node_version_with_valid_outer_checksum(self):
        self.build()
        self.rewrite_manifest({'node_version': '22.x'})
        with self.assertRaisesRegex(ValueError, 'inconsistent'):
            release.verify(self.artifact, self.checksum)

    def rewrite(self, name, data, kind=tarfile.REGTYPE, link=""):
        with tarfile.open(self.artifact) as archive:
            entries = [(entry, archive.extractfile(entry).read() if entry.isfile() else None) for entry in archive]
        with tarfile.open(self.artifact, "w:gz") as archive:
            for entry, content in entries:
                archive.addfile(entry, io.BytesIO(content) if content is not None else None)
            entry = tarfile.TarInfo(name)
            entry.size = len(data) if kind == tarfile.REGTYPE else 0
            entry.type, entry.linkname = kind, link
            archive.addfile(entry, io.BytesIO(data) if entry.isfile() else None)
        self.checksum.write_text(release.digest(self.artifact) + "  " + self.artifact.name + "\n")

    def test_actual_archive_excludes_credentials_runtime_and_cached_config(self):
        manifest = self.build()
        with tarfile.open(self.artifact) as archive:
            names = archive.getnames()
            data = b"".join(archive.extractfile(member).read() for member in archive if member.isfile())
        self.assertFalse(any(".env" in name or "customer.xlsx" in name or "config.php" in name or "database.sql" in name for name in names))
        self.assertNotIn(b"private", data)
        self.assertIn("nordipass-release/storage/framework/views", names)
        self.assertEqual(manifest["dependencies_sha256"], hashlib.sha256(b"{}").hexdigest())
        self.assertEqual(manifest["application_sha256"], hashlib.sha256(release.canonical({"Rule.php": release.digest(self.source / "app/Rule.php")})).hexdigest())
        self.assertEqual(release.verify(self.artifact, self.checksum)[0], manifest)

    def test_tampering_is_rejected_before_retention(self):
        self.build()
        with self.artifact.open("ab") as output:
            output.write(b"tampered")
        with self.assertRaisesRegex(ValueError, "checksum"):
            release.retain(self.artifact, self.checksum, self.root / "archive")
        self.assertFalse((self.root / "archive").exists())

    def test_translations_are_packaged_and_bound_to_the_installed_release(self):
        installed = self.installed_release()
        for name in ("lang/en.json", "lang/sv/auth.php"):
            self.assertEqual((installed / name).read_bytes(), (self.source / name).read_bytes())
        release.verify_tree(installed, self.root / "archive")
        (installed / "lang/sv/auth.php").write_text("<?php return ['failed' => 'changed'];")
        with self.assertRaisesRegex(ValueError, "file set or content changed"):
            release.verify_tree(installed, self.root / "archive")

    def test_checksum_for_another_filename_is_rejected(self):
        self.build()
        self.checksum.write_text(release.digest(self.artifact) + "  unrelated.tar.gz\n")
        with self.assertRaisesRegex(ValueError, "checksum"):
            release.verify(self.artifact, self.checksum)

    def test_duplicate_member_is_rejected_even_with_matching_checksum(self):
        self.build()
        self.rewrite("nordipass-release/artisan", b"replacement")
        with self.assertRaisesRegex(ValueError, "Duplicate"):
            release.verify(self.artifact, self.checksum)

    def test_traversal_and_links_are_rejected(self):
        for name, kind, link in (("nordipass-release/../../escape", tarfile.REGTYPE, ""),
                                 ("nordipass-release/app/link", tarfile.SYMTYPE, "/etc/passwd")):
            with self.subTest(name=name):
                self.build()
                self.rewrite(name, b"x", kind, link)
                with self.assertRaises(ValueError):
                    release.verify(self.artifact, self.checksum)
                self.artifact.unlink()
                self.checksum.unlink()

    def test_runtime_payload_is_rejected_even_with_matching_checksum(self):
        self.build()
        self.rewrite("nordipass-release/storage/app/private/passport.pdf", b"private")
        with self.assertRaisesRegex(ValueError, "Runtime"):
            release.verify(self.artifact, self.checksum)

    def test_local_database_snapshots_and_sidecars_are_excluded(self):
        names = ["database/database.sqlite", "database/database.sqlite-wal",
                 "database/database.sqlite-shm", "database/local.sqlite3-journal",
                 "database/backups/customer.db", "database/LOCAL.SQLITE3"]
        for name in names:
            path = self.source / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(b"customer database contents")
        migration = self.source / "database/migrations/2026_10_09_example.php"
        migration.parent.mkdir(parents=True, exist_ok=True)
        migration.write_text("<?php return true;")
        self.build()
        with tarfile.open(self.artifact) as archive:
            packaged = archive.getnames()
            data = b"".join(archive.extractfile(member).read() for member in archive if member.isfile())
        self.assertIn("nordipass-release/database/migrations/2026_10_09_example.php", packaged)
        for name in names:
            self.assertNotIn("nordipass-release/" + name, packaged)
        self.assertNotIn(b"customer database contents", data)

    def test_database_snapshot_injection_is_rejected_with_valid_checksum(self):
        for name in ("database/database.sqlite", "database/database.sqlite-wal", "database/backups/customer.db"):
            with self.subTest(name=name):
                self.build()
                self.rewrite("nordipass-release/" + name, b"customer database contents")
                with self.assertRaisesRegex(ValueError, "Runtime"):
                    release.verify(self.artifact, self.checksum)
                self.artifact.unlink()
                self.checksum.unlink()

    def test_immutable_retention_survives_source_removal_and_is_idempotent(self):
        manifest = self.build()
        destination = release.retain(self.artifact, self.checksum, self.root / "archive")
        self.assertEqual(release.retain(self.artifact, self.checksum, self.root / "archive"), destination)
        self.artifact.unlink()
        self.checksum.unlink()
        self.assertEqual(release.verify(destination / "release.tar.gz", destination / "release.tar.gz.sha256")[0], manifest)

    def test_archive_corruption_is_not_overwritten(self):
        self.build()
        destination = release.retain(self.artifact, self.checksum, self.root / "archive")
        (destination / "release.tar.gz").write_bytes(b"corrupt")
        with self.assertRaisesRegex(ValueError, "inconsistent"):
            release.retain(self.artifact, self.checksum, self.root / "archive")
        self.assertEqual((destination / "release.tar.gz").read_bytes(), b"corrupt")

    def test_missing_executable_dependency_is_rejected(self):
        (self.source / "composer.lock").unlink()
        with self.assertRaisesRegex(ValueError, "Missing"):
            self.build()
        self.assertFalse(self.artifact.exists())

    def test_build_does_not_overwrite_existing_artifact(self):
        self.build()
        original = self.artifact.read_bytes()
        with self.assertRaises(FileExistsError):
            self.build()
        self.assertEqual(self.artifact.read_bytes(), original)

    def installed_release(self):
        self.build()
        retained = release.retain(self.artifact, self.checksum, self.root / 'archive')
        installed = self.root / 'installed'
        installed.mkdir()
        with tarfile.open(self.artifact) as archive:
            # The verifier has already rejected links, duplicates and traversal.
            for member in archive:
                path = installed / member.name
                if member.isdir():
                    path.mkdir(parents=True, exist_ok=True)
                else:
                    path.parent.mkdir(parents=True, exist_ok=True)
                    path.write_bytes(archive.extractfile(member).read())
        installed = installed / release.ROOT
        (installed / 'ARTIFACT_SHA256').write_text(retained.name)
        return installed

    def test_installed_tree_is_bound_to_retained_code_and_allows_runtime_cache(self):
        installed = self.installed_release()
        (installed / 'bootstrap/cache/config.php').write_text('runtime secret')
        (installed / '.env').write_text('runtime secret')
        self.assertEqual(release.verify_tree(installed, self.root / 'archive')['commit'], 'a' * 40)
        cli = subprocess.run([sys.executable, str(Path(release.__file__)), 'verify-tree', '--release-dir', str(installed),
                              '--archive-root', str(self.root / 'archive')], capture_output=True, text=True)
        self.assertEqual(cli.returncode, 0, cli.stderr)
        (installed / 'app/Rule.php').write_text('<?php return false;')
        with self.assertRaisesRegex(ValueError, 'file set or content changed'):
            release.verify_tree(installed, self.root / 'archive')

    def test_new_executable_file_cannot_enter_a_historical_release(self):
        installed = self.installed_release()
        (installed / 'public/backdoor.php').write_text('<?php')
        with self.assertRaisesRegex(ValueError, 'file set or content changed'):
            release.verify_tree(installed, self.root / 'archive')

    @unittest.skipUnless(os.name == 'posix', 'Native Unix file permission contract')
    def test_private_release_prepares_public_assets_without_exposing_private_or_shared_inputs(self):
        installed = self.installed_release()
        for parent, dirs, files in os.walk(installed):
            Path(parent).chmod(0o700)
            for name in files:
                (Path(parent) / name).chmod(0o600)
        (installed / '.env').write_text('private')
        (installed / '.env').chmod(0o600)
        shared = self.root / 'shared-private'
        shared.mkdir(mode=0o700)
        secret = shared / 'secret.txt'
        secret.write_text('private')
        secret.chmod(0o600)
        (installed / 'public/storage').symlink_to(shared, target_is_directory=True)
        release.prepare_public(installed, self.root / 'archive')
        mode = lambda path: stat.S_IMODE(path.stat().st_mode)
        self.assertEqual(mode(installed), 0o711)
        self.assertEqual(mode(installed / 'public/build'), 0o755)
        self.assertEqual(mode(installed / 'public/build/manifest.json'), 0o644)
        for path, expected in ((installed / '.env', 0o600), (installed / 'app', 0o700),
                               (installed / 'app/Rule.php', 0o600), (installed / 'storage', 0o700),
                               (shared, 0o700), (secret, 0o600)):
            self.assertEqual(mode(path), expected)
        release.verify_tree(installed, self.root / 'archive')

    @unittest.skipUnless(os.name == 'posix', 'Native Unix file permission contract')
    def test_public_runtime_secret_rejects_preparation_before_any_permission_change(self):
        installed = self.installed_release()
        installed.chmod(0o700)
        secret = installed / 'public/.env'
        secret.write_text('private')
        secret.chmod(0o600)
        with self.assertRaisesRegex(ValueError, 'Unreviewed public file'):
            release.prepare_public(installed, self.root / 'archive')
        self.assertEqual(stat.S_IMODE(installed.stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE(secret.stat().st_mode), 0o600)

    @unittest.skipUnless(os.name == 'posix', 'Native Unix file permission contract')
    def test_public_preparation_rejects_alias_release_before_chmod(self):
        installed = self.installed_release()
        installed.chmod(0o700)
        alias = self.root / 'current'
        alias.symlink_to(installed, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, 'explicit nonsymlink'):
            release.prepare_public(alias, self.root / 'archive')
        self.assertEqual(stat.S_IMODE(installed.stat().st_mode), 0o700)

    @unittest.skipUnless(os.name == 'posix', 'Native Unix file permission contract')
    def test_public_preparation_rejects_changed_bytes_before_chmod(self):
        installed = self.installed_release()
        installed.chmod(0o700)
        (installed / 'public/build/manifest.json').write_text('changed')
        with self.assertRaisesRegex(ValueError, 'file set or content changed'):
            release.prepare_public(installed, self.root / 'archive')
        self.assertEqual(stat.S_IMODE(installed.stat().st_mode), 0o700)


if __name__ == "__main__":
    unittest.main()
