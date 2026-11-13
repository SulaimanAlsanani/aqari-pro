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
    if len(ports) != 1 or ports[0].get("host_ip") != "127.0.0.1" or str(ports[0].get("published")) != "18080":
        raise ValueError("Staging application must bind port 18080 on loopback only")
    if app.get("environment", {}).get("APP_ENV") != "staging":
        raise ValueError("Staging environment must be explicit")
    if str(app.get("environment", {}).get("APP_DEBUG", "")).lower() != "false":
        raise ValueError("Debug must be disabled")
    if app.get("environment", {}).get("DB_DATABASE") != "aqari_staging":
        raise ValueError("Unexpected staging database name")
    if set(db.get("networks", {})) != {"internal"}:
        raise ValueError("Database may only join the private internal network")
    if not {"internal", "outbound"}.issubset(set(app.get("networks", {}))):
        raise ValueError("Application needs private DB and external SMTP/S3 networks")
    internal = compose.get("networks", {}).get("internal", {})
    if internal.get("internal") is not True:
        raise ValueError("Database network must be internal")
    for service in (app, db):
        if service.get("privileged") is True or service.get("network_mode") == "host":
            raise ValueError("Privileged/host networking forbidden")
        for volume in service.get("volumes", []):
            if volume.get("type") == "bind":
                raise ValueError("Host bind mounts are forbidden in standalone staging")


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
