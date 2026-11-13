#!/usr/bin/env python3
"""Static guard for rendered staging Docker Compose config; no containers started."""
import json
import sys


def validate(compose: dict) -> None:
    if compose.get("name") != "aqari-pro-staging":
        raise ValueError("Unexpected Compose project name")
    services = compose.get("services", {})
    if set(services) != {"app", "db"}:
        raise ValueError("Staging services must be app and db only")
    app, db = services["app"], services["db"]
    if db.get("ports"):
        raise ValueError("Staging database must never expose ports")
    ports = app.get("ports") or []
    if len(ports) != 1 or ports[0].get("host_ip") != "127.0.0.1" or str(ports[0].get("published")) != "18080" or str(ports[0].get("target")) != "80":
        raise ValueError("Staging application must bind port 18080 on loopback only")
    if app.get("environment", {}).get("APP_ENV") != "staging":
        raise ValueError("Staging environment must be explicit")
    if str(app.get("environment", {}).get("APP_DEBUG", "")).lower() != "false":
        raise ValueError("Debug must be disabled")
    app_env = app.get("environment", {})
    if (app_env.get("DB_DATABASE") != "aqari_staging" or
            app_env.get("DB_HOST") != "db" or
            app_env.get("DB_USERNAME") != "aqari_staging" or
            app_env.get("DB_CONNECTION") != "mysql"):
        raise ValueError("Staging application must use its dedicated MySQL database and account")
    if str(app_env.get("DOCUMENT_SCAN_ENABLED", "")).lower() != "true":
        raise ValueError("Staging must scan private documents")
    if set(db.get("networks", {})) != {"internal"}:
        raise ValueError("Database may only join the private internal network")
    if set(app.get("networks", {})) != {"internal", "outbound"}:
        raise ValueError("Application needs private DB and external SMTP/S3 networks")
    internal = compose.get("networks", {}).get("internal", {})
    if internal.get("internal") is not True:
        raise ValueError("Database network must be internal")
    expected_mounts = {
        "app": {
            ("staging_private", "/var/www/html/storage"),
            ("staging_clamav", "/var/lib/clamav"),
        },
        "db": {("staging_mysql", "/var/lib/mysql")},
    }
    allowed_volumes = set().union(
        *(set(source for source, _ in mounts) for mounts in expected_mounts.values())
    )
    volumes = compose.get("volumes", {})
    if set(volumes) != allowed_volumes:
        raise ValueError("Unexpected staging named volumes")
    for volume_name, details in volumes.items():
        if not isinstance(details, dict) or details.get("external"):
            raise ValueError("Externally managed or malformed staging volumes are forbidden")
        # Rendered Compose may add a project-scoped name automatically.
        resolved = details.get("name")
        if resolved and resolved != f"aqari-pro-staging_{volume_name}":
            raise ValueError("Staging volumes may not reference another project")
    for service_name, service in (("app", app), ("db", db)):
        if service.get("privileged") is True or service.get("network_mode") == "host":
            raise ValueError("Privileged/host networking forbidden")
        mounts = service.get("volumes", [])
        if any(v.get("type") != "volume" for v in mounts):
            raise ValueError("Host binds and anonymous mounts are forbidden")
        actual = {(v.get("source"), v.get("target")) for v in mounts}
        if len(actual) != len(mounts) or actual != expected_mounts[service_name]:
            raise ValueError("Staging service volume mounts do not match the approved set")


def main() -> int:
    try:
        validate(json.load(sys.stdin))
    except (ValueError, TypeError, KeyError, json.JSONDecodeError) as exc:
        print("FAIL: staging Compose isolation gate: " + str(exc), file=sys.stderr)
        return 1
    print("PASS: staging Compose isolation gate")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
