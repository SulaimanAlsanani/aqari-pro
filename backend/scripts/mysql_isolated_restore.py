#!/usr/bin/env python3
"""Isolated MySQL 8.4 restore drill for an already-verified Aqari Pro staged snapshot.

Safety boundary: starts a disposable Docker container without networking, ports,
host mounts, or application database credentials. NEVER imports into a live DB.
"""
from __future__ import annotations

import argparse
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys
import time
import uuid

IMAGE = "mysql:8.4"
MAX_DUMP_BYTES = 128 * 1024 * 1024
NAME_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")


def validated_staging_path(stage: str, app_storage: Path) -> Path:
    root = (app_storage / "restore-staging").resolve(strict=True)
    if not root.is_dir():
        raise ValueError("Restore staging root is missing")
    raw = Path(stage).expanduser().absolute()
    if raw.is_symlink() or not NAME_RE.fullmatch(raw.name):
        raise ValueError("Staging path must be a non-symlink UUID directory")
    folder = raw.resolve(strict=True)
    if folder.parent != root or not folder.is_dir():
        raise ValueError("Refusing files outside the dedicated restore-staging root")
    sql = folder / "database.mysql.sql"
    if sql.is_symlink() or not sql.is_file():
        raise ValueError("Verified MySQL SQL file is missing or a symlink")
    if sql.stat().st_size < 1 or sql.stat().st_size > MAX_DUMP_BYTES:
        raise ValueError("MySQL SQL file exceeds the bounded restore size")
    if sql.resolve(strict=True).parent != folder:
        raise ValueError("SQL file escapes staging folder")
    return sql


def run_command(args: list[str], *, input_file=None, env=None, timeout=120) -> subprocess.CompletedProcess:
    return subprocess.run(
        args, stdin=input_file, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        check=True, timeout=timeout, env=env,
    )



def reconcile_documents(rows: bytes, staged: Path) -> int:
    """Match restored document DB rows to private staged owner files.

    SQL returns user_id, HEX(path), size as TSV, avoiding ambiguous escaping
    of filenames and never logging private names or row contents.
    """
    documents = staged / "attachments" / "owners"
    expected: dict[str, int] = {}
    if len(rows) > 8 * 1024 * 1024:
        raise RuntimeError("Document listing exceeds audit limit")
    for row in rows.splitlines():
        if not row:
            continue
        cols = row.split(b"\t")
        if len(cols) != 3:
            raise RuntimeError("Malformed document record from restored MySQL")
        try:
            owner = int(cols[0])
            path = bytes.fromhex(cols[1].decode("ascii")).decode("utf-8")
            size = int(cols[2])
        except (ValueError, UnicodeError) as exc:
            raise RuntimeError("Invalid document record in restored MySQL") from exc
        if owner < 1 or size < 0 or size > MAX_DUMP_BYTES:
            raise RuntimeError("Invalid document owner or size")
        if not re.fullmatch(r"owners/[0-9]+/documents/[A-Za-z0-9_./-]+", path):
            raise RuntimeError("Unsafe restored document path")
        if ".." in path or not path.startswith(f"owners/{owner}/documents/"):
            raise RuntimeError("Restored document has mismatched owner")
        if path in expected:
            raise RuntimeError("Duplicate document record path")
        expected[path] = size

    actual: dict[str, int] = {}
    if documents.exists():
        if documents.is_symlink() or not documents.is_dir():
            raise RuntimeError("Invalid restored attachments directory")
        for item in documents.rglob("*"):
            if item.is_symlink():
                raise RuntimeError("Symlink in restored attachments")
            if item.is_file():
                rel = item.relative_to(staged / "attachments").as_posix()
                if rel not in expected:
                    raise RuntimeError("Orphan attachment in restored snapshot")
                actual[rel] = item.stat().st_size
            elif not item.is_dir():
                raise RuntimeError("Unexpected attachment entry")
    if expected != actual:
        raise RuntimeError("Restored MySQL document records do not match staged attachments")
    return len(expected)



# Mandatory restored property-management relations. We check them in a
# disposable database; application-level relationships may be stricter than FK.
DOMAIN_TABLES = ("users", "properties", "units", "tenants", "leases", "payments", "dues")
DOMAIN_CHECKS = {
    "units_without_property": (
        "SELECT COUNT(*) FROM units u LEFT JOIN properties p ON p.id=u.property_id "
        "WHERE p.id IS NULL"
    ),
    "leases_without_unit": (
        "SELECT COUNT(*) FROM leases l LEFT JOIN units u ON u.id=l.unit_id "
        "WHERE u.id IS NULL"
    ),
    "leases_without_tenant": (
        "SELECT COUNT(*) FROM leases l LEFT JOIN tenants t ON t.id=l.tenant_id "
        "WHERE t.id IS NULL"
    ),
    "payments_without_lease": (
        "SELECT COUNT(*) FROM payments p LEFT JOIN leases l ON l.id=p.lease_id "
        "WHERE l.id IS NULL"
    ),
    "dues_without_lease": (
        "SELECT COUNT(*) FROM dues d LEFT JOIN leases l ON l.id=d.lease_id "
        "WHERE l.id IS NULL"
    ),
    "cross_owner_leases": (
        "SELECT COUNT(*) FROM leases l JOIN units u ON u.id=l.unit_id "
        "JOIN properties p ON p.id=u.property_id "
        "JOIN tenants t ON t.id=l.tenant_id WHERE p.user_id<>t.user_id"
    ),
    "properties_without_owner": (
        "SELECT COUNT(*) FROM properties p LEFT JOIN users u ON u.id=p.user_id "
        "WHERE u.id IS NULL"
    ),
    "tenants_without_owner": (
        "SELECT COUNT(*) FROM tenants t LEFT JOIN users u ON u.id=t.user_id "
        "WHERE u.id IS NULL"
    ),
}

def verify_domain_integrity(fetch_scalar) -> int:
    """Run strict relational checks; missing tables/SQL errors must fail closed."""
    table_query = (
        "SELECT COUNT(*) FROM information_schema.tables "
        "WHERE table_schema=DATABASE() AND TABLE_TYPE='BASE TABLE' AND "
        "table_name IN (" + ",".join("'" + x + "'" for x in DOMAIN_TABLES) + ")"
    )
    total = fetch_scalar(table_query)
    if total != len(DOMAIN_TABLES):
        raise RuntimeError("Restored property-management tables are incomplete")
    for check, query in DOMAIN_CHECKS.items():
        count = fetch_scalar(query)
        if count != 0:
            raise RuntimeError("Restored property-management relationship check failed: " + check)
    return len(DOMAIN_CHECKS)


def run_drill(sql: Path, *, image: str = IMAGE) -> None:
    if image != IMAGE:
        raise ValueError("Only the pinned MySQL major/minor image is supported")
    if os.environ.get("DOCKER_HOST") or os.environ.get("DOCKER_CONTEXT"):
        raise ValueError("Remote or overridden Docker contexts are not supported")
    # A selected remote Docker context can exist even with both environment variables unset.
    # Explicitly inspect the active daemon endpoint before provisioning any container.
    print("RESTORE-DRILL: validating local Docker context", file=sys.stderr, flush=True)
    endpoint = run_command(["docker", "context", "inspect", "--format", "{{.Endpoints.docker.Host}}"], timeout=15)
    docker_host = endpoint.stdout.decode("utf-8", errors="replace").strip()
    if not docker_host.startswith("unix:///") or "\\n" in docker_host:
        raise ValueError("Docker daemon must use a local Unix socket")
    password = secrets.token_urlsafe(36)
    name = "aqari-restore-" + uuid.uuid4().hex[:16]
    created = False
    try:
        # All resources, including data files, stay in the isolated disposable container.
        print("RESTORE-DRILL: starting a disposable networkless MySQL container", file=sys.stderr, flush=True)
        run_command([
            "docker", "run", "--detach", "--rm", "--network", "none",
            "--name", name, "--label", "aqari.restore-drill=ephemeral",
            "--tmpfs", "/var/lib/mysql:rw,noexec,nosuid,size=536870912",
            "--tmpfs", "/tmp:rw,nosuid,size=67108864",
            "--env", "MYSQL_ROOT_PASSWORD=" + password,
            "--env", "MYSQL_DATABASE=aqari_restore",
            image, "--skip-networking",
        ])
        created = True
        # The official MySQL Docker entrypoint first runs a *temporary*
        # bootstrap server. mysqladmin ping may return success even when its
        # root credentials are not yet configured, or before the bootstrap
        # daemon is replaced by the final server. Wait for the final PID 1
        # process AND a properly authenticated SQL query.
        print("RESTORE-DRILL: waiting for final authenticated MySQL server", file=sys.stderr, flush=True)
        ready = False
        for _ in range(60):
            try:
                init = run_command([
                    "docker", "exec", name, "cat", "/proc/1/comm",
                ], timeout=10)
                if init.stdout.strip() != b"mysqld":
                    time.sleep(2)
                    continue
                sql_ready = run_command([
                    "docker", "exec", "--env", "MYSQL_PWD=" + password, name,
                    "mysql", "--protocol=socket", "-uroot", "--batch",
                    "--skip-column-names", "--execute", "SELECT 1",
                ], timeout=10)
                if sql_ready.stdout.strip() == b"1":
                    ready = True
                    break
            except (subprocess.CalledProcessError, subprocess.TimeoutExpired):
                pass
            time.sleep(2)
        if not ready:
            raise RuntimeError("Final isolated MySQL server did not pass authenticated readiness")

        print("RESTORE-DRILL: disposable MySQL is ready; importing staged SQL", file=sys.stderr, flush=True)
        with sql.open("rb") as stream:
            run_command([
                "docker", "exec", "--interactive", "--env", "MYSQL_PWD=" + password,
                name, "mysql", "--protocol=socket", "-uroot",
                "--default-character-set=utf8mb4", "--binary-mode",
                "--max-allowed-packet=64M",
            ], input_file=stream, timeout=300)

        print("RESTORE-DRILL: SQL import completed; discovering restored schema", file=sys.stderr, flush=True)
        # The dump uses --databases, so it may create/select its own schema.
        # This query is scoped to the isolated instance and verifies usable tables exist.
        query = ("SELECT COUNT(*) FROM information_schema.tables "
                 "WHERE table_schema NOT IN ('mysql','sys','performance_schema',"
                 "'information_schema') AND table_type='BASE TABLE';")
        result = run_command([
            "docker", "exec", "--env", "MYSQL_PWD=" + password, name, "mysql",
            "--protocol=socket", "-uroot", "--batch", "--skip-column-names",
            "--execute", query,
        ], timeout=30)
        try:
            tables = int(result.stdout.decode("utf-8").strip())
        except ValueError as exc:
            raise RuntimeError("Could not inspect recovered MySQL table count") from exc
        if tables < 1:
            raise RuntimeError("Restored MySQL contains no application tables")
        print("RESTORE-DRILL: table checks completed; identifying restored domain schema", file=sys.stderr, flush=True)
        # Find exactly one application schema containing the documents table.
        schema_result = run_command([
            "docker", "exec", "--env", "MYSQL_PWD=" + password, name, "mysql",
            "--protocol=socket", "-uroot", "--batch", "--raw", "--skip-column-names",
            "--execute", ("SELECT TABLE_SCHEMA FROM information_schema.TABLES "
                          "WHERE TABLE_NAME='documents' AND TABLE_TYPE='BASE TABLE' "
                          "AND TABLE_SCHEMA NOT IN "
                          "('mysql','sys','performance_schema','information_schema')"),
        ], timeout=30)
        schemas = schema_result.stdout.decode("utf-8").strip().splitlines()
        if len(schemas) != 1 or not re.fullmatch(r"[A-Za-z0-9_]+", schemas[0]):
            raise RuntimeError("Expected exactly one restored application documents table")
        schema = schemas[0]
        # Validate only the restored application schema, not system databases
        # such as performance_schema that may contain unsupported table types.
        print("RESTORE-DRILL: checking restored application tables", file=sys.stderr, flush=True)
        run_command([
            "docker", "exec", "--env", "MYSQL_PWD=" + password, name,
            "mysqlcheck", "--protocol=socket", "-uroot",
            "--databases", schema, "--check", "--silent",
        ], timeout=120)

        doc_result = run_command([
            "docker", "exec", "--env", "MYSQL_PWD=" + password, name, "mysql",
            "--protocol=socket", "-uroot", "--batch", "--raw", "--skip-column-names",
            "--database", schema,
            "--execute", "SELECT user_id, HEX(path), size FROM documents ORDER BY id",
        ], timeout=30)
        def fetch_scalar(query: str) -> int:
            response = run_command([
                "docker", "exec", "--env", "MYSQL_PWD=" + password, name, "mysql",
                "--protocol=socket", "-uroot", "--batch", "--raw", "--skip-column-names",
                "--database", schema, "--execute", query,
            ], timeout=30)
            try:
                values = response.stdout.decode("ascii").strip().splitlines()
                if len(values) != 1:
                    raise ValueError("Expected one scalar")
                number = int(values[0])
                if number < 0:
                    raise ValueError("Negative scalar")
                return number
            except (UnicodeError, ValueError) as exc:
                raise RuntimeError("Could not read isolated integrity check result") from exc

        print("RESTORE-DRILL: validating owner relations and private attachments", file=sys.stderr, flush=True)
        domain_checks = verify_domain_integrity(fetch_scalar)
        document_count = reconcile_documents(doc_result.stdout, sql.parent)
        print(f"PASS: isolated MySQL import, mysqlcheck and attachment reconciliation complete; "
              f"{tables} tables, {document_count} documents, {domain_checks} domain checks.")
        print("No live database connection or production attachments were modified.")
    finally:
        if created:
            subprocess.run(
                ["docker", "rm", "--force", name], stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL, check=False, timeout=30,
            )


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--stage", required=True, help="UUID directory produced by backup:stage-restore")
    parser.add_argument("--app-storage", required=True, help="Absolute path to backend/storage/app")
    parser.add_argument("--validate-only", action="store_true", help="Check paths without starting Docker")
    args = parser.parse_args()
    try:
        sql = validated_staging_path(args.stage, Path(args.app_storage))
        if args.validate_only:
            print("PASS: staged MySQL SQL path and size validated; no import was performed.")
        else:
            run_drill(sql)
        return 0
    except (ValueError, RuntimeError, OSError, subprocess.SubprocessError) as exc:
        # Do not expose SQL contents or client stdout/stderr, which may contain secrets.
        # subprocess errors may embed the ephemeral password in argv; never print them.
        print("FAIL: isolated MySQL restore drill. Inspect protected local logs.", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
