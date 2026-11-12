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


def run_drill(sql: Path, *, image: str = IMAGE) -> None:
    if image != IMAGE:
        raise ValueError("Only the pinned MySQL major/minor image is supported")
    if os.environ.get("DOCKER_HOST") or os.environ.get("DOCKER_CONTEXT"):
        raise ValueError("Remote or overridden Docker contexts are not supported")
    password = secrets.token_urlsafe(36)
    name = "aqari-restore-" + uuid.uuid4().hex[:16]
    created = False
    try:
        # All resources, including data files, stay in the isolated disposable container.
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
        # Docker exec passes only the random *disposable* password, not production credentials.
        ready = False
        for _ in range(60):
            try:
                run_command([
                    "docker", "exec", "--env", "MYSQL_PWD=" + password, name,
                    "mysqladmin", "--protocol=socket", "-uroot", "ping",
                ], timeout=10)
                ready = True
                break
            except (subprocess.CalledProcessError, subprocess.TimeoutExpired):
                time.sleep(2)
        if not ready:
            raise RuntimeError("Isolated MySQL instance failed readiness check")

        with sql.open("rb") as stream:
            run_command([
                "docker", "exec", "--interactive", "--env", "MYSQL_PWD=" + password,
                name, "mysql", "--protocol=socket", "-uroot",
                "--default-character-set=utf8mb4", "--binary-mode",
                "--max-allowed-packet=64M",
            ], input_file=stream, timeout=300)

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
        print(f"PASS: isolated MySQL import completed; {tables} base tables detected.")
        print("Live database connections, ports, and attachments were not used.")
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
