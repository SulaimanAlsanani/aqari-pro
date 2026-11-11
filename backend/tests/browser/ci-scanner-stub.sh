#!/usr/bin/env bash
# Disposable CI ONLY. This is NOT an antivirus and must never be copied to a real server.
# Permit only the exact 68-byte synthetic PNG used in the browser E2E suite.
set -Eeuo pipefail
[[ "$#" -eq 3 && "$1" == "--no-summary" && "$2" == "--" ]] || exit 2
[[ -f "$3" ]] || exit 2
expected='4123e378c1074cae079bc1e687d4952165117fb00989dfa94ace408c82109ae2'
actual="$(sha256sum -- "$3" | cut -d ' ' -f 1)"
if [[ "$actual" == "$expected" ]]; then
  exit 0
fi
echo "CI fixture mismatch; synthetic scanner rejects this content." >&2
exit 1
