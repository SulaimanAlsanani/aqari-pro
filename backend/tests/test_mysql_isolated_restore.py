"""Tests for the MySQL disposable restore drill's safety checks."""
import importlib.util
import pathlib
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = pathlib.Path(__file__).resolve().parent.parent / "scripts" / "mysql_isolated_restore.py"
spec = importlib.util.spec_from_file_location("mysql_isolated_restore", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class RestoreDrillSafetyTest(unittest.TestCase):
    def setUp(self):
        self.workspace = tempfile.TemporaryDirectory()
        self.addCleanup(self.workspace.cleanup)
        self.storage = pathlib.Path(self.workspace.name) / "storage" / "app"
        self.root = self.storage / "restore-staging"
        self.root.mkdir(parents=True)
        self.stage = self.root / "123e4567-e89b-12d3-a456-426614174000"
        self.stage.mkdir()
        self.sql = self.stage / "database.mysql.sql"
        self.sql.write_text("-- mysql dump\nCREATE TABLE probe (id int);\n")

    def test_valid_staged_sql_is_accepted(self):
        self.assertEqual(module.validated_staging_path(str(self.stage), self.storage), self.sql)

    def test_outside_staging_root_is_rejected(self):
        rogue = pathlib.Path(self.workspace.name) / "123e4567-e89b-12d3-a456-426614174001"
        rogue.mkdir()
        (rogue / "database.mysql.sql").write_text("CREATE TABLE probe(id int);")
        with self.assertRaises(ValueError):
            module.validated_staging_path(str(rogue), self.storage)

    def test_symlinked_sql_is_rejected(self):
        self.sql.unlink()
        outside = pathlib.Path(self.workspace.name) / "outside.sql"
        outside.write_text("SELECT 1;")
        self.sql.symlink_to(outside)
        with self.assertRaises(ValueError):
            module.validated_staging_path(str(self.stage), self.storage)

    def test_empty_dump_is_rejected(self):
        self.sql.write_text("")
        with self.assertRaises(ValueError):
            module.validated_staging_path(str(self.stage), self.storage)

    def test_remote_context_fails_before_launching_docker(self):
        with patch.dict(module.os.environ, {"DOCKER_HOST": "tcp://remote:2375"}):
            with patch.object(module, "run_command") as runner:
                with self.assertRaises(ValueError):
                    module.run_drill(self.sql)
                runner.assert_not_called()

    def test_remote_selected_context_is_rejected_before_container_creation(self):
        with patch.dict(module.os.environ, {}, clear=True):
            with patch.object(module, "run_command", return_value=type("Done", (), {"stdout": b"ssh://remote-host/run/docker.sock\\n"})()) as runner:
                with self.assertRaises(ValueError):
                    module.run_drill(self.sql)
                runner.assert_called_once()
                self.assertEqual(runner.call_args.args[0][:3], ["docker", "context", "inspect"])

    def test_tcp_context_is_rejected_before_container_creation(self):
        with patch.dict(module.os.environ, {}, clear=True):
            with patch.object(module, "run_command", return_value=type("Done", (), {"stdout": b"tcp://127.0.0.1:2375\\n"})()) as runner:
                with self.assertRaises(ValueError):
                    module.run_drill(self.sql)
                runner.assert_called_once()

    def test_restored_document_matches_private_file(self):
        target = self.stage / "attachments" / "owners" / "1" / "documents"
        target.mkdir(parents=True)
        (target / "file.pdf").write_bytes(b"PDF")
        rows = b"1\t" + b"owners/1/documents/file.pdf".hex().encode() + b"\t3\n"
        self.assertEqual(module.reconcile_documents(rows, self.stage), 1)

    def test_missing_restored_document_fails(self):
        rows = b"1\t" + b"owners/1/documents/missing.pdf".hex().encode() + b"\t3\n"
        with self.assertRaises(RuntimeError):
            module.reconcile_documents(rows, self.stage)

    def test_orphan_restored_attachment_fails(self):
        target = self.stage / "attachments" / "owners" / "1" / "documents"
        target.mkdir(parents=True)
        (target / "orphan.pdf").write_bytes(b"PDF")
        with self.assertRaises(RuntimeError):
            module.reconcile_documents(b"", self.stage)

    def test_wrong_restored_document_size_fails(self):
        target = self.stage / "attachments" / "owners" / "1" / "documents"
        target.mkdir(parents=True)
        (target / "file.pdf").write_bytes(b"PDF")
        rows = b"1\t" + b"owners/1/documents/file.pdf".hex().encode() + b"\t99\n"
        with self.assertRaises(RuntimeError):
            module.reconcile_documents(rows, self.stage)

    def test_cross_owner_document_path_fails(self):
        rows = b"2\t" + b"owners/1/documents/file.pdf".hex().encode() + b"\t3\n"
        with self.assertRaises(RuntimeError):
            module.reconcile_documents(rows, self.stage)

    def test_restored_property_relations_pass(self):
        def scalar(query):
            if "information_schema.tables" in query:
                return len(module.DOMAIN_TABLES)
            return 0
        self.assertEqual(module.verify_domain_integrity(scalar), len(module.DOMAIN_CHECKS))

    def test_missing_critical_property_tables_fails_closed(self):
        with self.assertRaises(RuntimeError):
            module.verify_domain_integrity(lambda query: len(module.DOMAIN_TABLES) - 1)

    def test_orphan_lease_or_payment_fails_domain_audit(self):
        def scalar(query):
            if "information_schema.tables" in query:
                return len(module.DOMAIN_TABLES)
            if "LEFT JOIN leases l" in query:
                return 1
            return 0
        with self.assertRaises(RuntimeError):
            module.verify_domain_integrity(scalar)

    def test_cross_owner_lease_fails_domain_audit(self):
        def scalar(query):
            if "information_schema.tables" in query:
                return len(module.DOMAIN_TABLES)
            if "p.user_id<>t.user_id" in query:
                return 1
            return 0
        with self.assertRaises(RuntimeError):
            module.verify_domain_integrity(scalar)

    def test_docker_container_is_networkless_and_mountless(self):
        calls = []

        def fake_run(args, **kwargs):
            calls.append(args)
            if args[:3] == ["docker", "context", "inspect"]:
                return type("Done", (), {"stdout": b"unix:///var/run/docker.sock\n"})()
            if args[1] == "exec" and "mysql" in args:
                if "--execute" in args:
                    sql = args[args.index("--execute") + 1]
                    if "TABLE_NAME='documents'" in sql:
                        return type("Done", (), {"stdout": b"aqari_restore_probe\n"})()
                    if "HEX(path)" in sql:
                        return type("Done", (), {"stdout": b""})()
                    if "table_schema=DATABASE()" in sql:
                        return type("Done", (), {"stdout": str(len(module.DOMAIN_TABLES)).encode() + b"\n"})()
                    if any(mark in sql for mark in ("LEFT JOIN", "p.user_id<>t.user_id")):
                        return type("Done", (), {"stdout": b"0\n"})()
                    return type("Done", (), {"stdout": b"3\n"})()
                return type("Done", (), {"stdout": b""})()
            return type("Done", (), {"stdout": b"ok\n"})()

        with patch.dict(module.os.environ, {}, clear=True):
            with patch.object(module, "run_command", side_effect=fake_run):
                with patch.object(module.subprocess, "run") as cleanup:
                    module.run_drill(self.sql)
                    cleanup.assert_called_once()
        check_calls = [args for args in calls if args[:2] == ["docker", "exec"] and "mysqlcheck" in args]
        self.assertEqual(len(check_calls), 1)
        self.assertNotIn("--all-databases", check_calls[0])
        self.assertIn("--databases", check_calls[0])
        self.assertEqual(check_calls[0][check_calls[0].index("--databases") + 1], "aqari_restore_probe")
        launch = next(args for args in calls if args[:2] == ["docker", "run"])
        self.assertEqual(launch[:2], ["docker", "run"])
        self.assertEqual(launch[launch.index("--network") + 1], "none")
        self.assertNotIn("--publish", launch)
        self.assertNotIn("--volume", launch)
        self.assertNotIn("--mount", launch)
        self.assertNotIn("--network=host", launch)
        self.assertEqual(launch[-2:], [module.IMAGE, "--skip-networking"])


if __name__ == "__main__":
    unittest.main()
