#!/usr/bin/env bash
# Deterministic CI tests for the ClamAV readiness gate. Never modifies real signatures.
set -euo pipefail

root="$(mktemp -d)"
trap 'rm -rf "$root"' EXIT
mkdir -p "$root/signatures"
cat > "$root/scanner" <<'EOF'
#!/bin/sh
# A harmless scanner stand-in for testing the readiness script's control flow.
[ -f "$4" ] || exit 2
exit 0
EOF
chmod +x "$root/scanner"
check="$(dirname "$0")/check-clamav-readiness.sh"

expect_fail() {
  if DOCUMENT_CLAMSCAN_BINARY="$root/scanner" CLAMAV_DATABASE_DIR="$root/signatures" \
    CLAMAV_MAX_SIGNATURE_AGE_HOURS=72 bash "$check" >/dev/null 2>&1; then
    echo "FAIL: Readiness incorrectly accepted $1" >&2
    exit 1
  fi
}

expect_fail "missing signatures"
printf 'CI synthetic signatures\n' > "$root/signatures/daily.cvd"
touch -d '5 days ago' "$root/signatures/daily.cvd"
expect_fail "stale signatures"
touch "$root/signatures/daily.cvd"

if ! DOCUMENT_CLAMSCAN_BINARY="$root/scanner" CLAMAV_DATABASE_DIR="$root/signatures" \
  CLAMAV_MAX_SIGNATURE_AGE_HOURS=72 bash "$check"; then
  echo "FAIL: Readiness rejected synthetic fresh-signature test" >&2
  exit 1
fi

# A failed scan must never be reported as ready.
cat > "$root/scanner" <<'EOF'
#!/bin/sh
exit 2
EOF
chmod +x "$root/scanner"
expect_fail "failed scan"
echo "PASS: ClamAV readiness rejects missing, stale and failed scans, accepts fresh fixture"
