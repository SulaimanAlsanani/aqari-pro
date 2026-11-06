#!/usr/bin/env bash
# Pre-launch operator check; run inside the same app container that accepts uploads.
set -euo pipefail

binary="${DOCUMENT_CLAMSCAN_BINARY:-/usr/bin/clamscan}"
database="${CLAMAV_DATABASE_DIR:-/var/lib/clamav}"
max_hours="${CLAMAV_MAX_SIGNATURE_AGE_HOURS:-72}"

if [[ ! -x "$binary" ]]; then
  echo "FAIL: ClamAV executable is missing: $binary" >&2
  exit 1
fi
if [[ ! -d "$database" ]]; then
  echo "FAIL: ClamAV signature directory is missing: $database" >&2
  exit 1
fi
if ! [[ "$max_hours" =~ ^[1-9][0-9]*$ ]]; then
  echo "FAIL: CLAMAV_MAX_SIGNATURE_AGE_HOURS must be a positive integer" >&2
  exit 1
fi

# Require at least one current main/daily signature database, not just an empty directory.
fresh=0
while IFS= read -r -d '' signature; do
  if find "$signature" -maxdepth 0 -mmin "-$((max_hours * 60))" -print -quit | grep -q .; then
    fresh=1
    break
  fi
done < <(find "$database" -maxdepth 1 -type f \
  \( -name 'main.cvd' -o -name 'main.cld' -o -name 'daily.cvd' -o -name 'daily.cld' \) -print0)

if [[ "$fresh" -ne 1 ]]; then
  echo "FAIL: No main/daily signature database updated in the last $max_hours hours" >&2
  echo "Run freshclam and retry before accepting customer uploads." >&2
  exit 1
fi

sample="$(mktemp)"
trap 'rm -f "$sample"' EXIT
printf 'Aqari Pro harmless antivirus readiness check\n' > "$sample"
if ! "$binary" --database="$database" --no-summary -- "$sample" >/dev/null; then
  echo "FAIL: ClamAV could not scan a harmless sample with the configured signatures" >&2
  exit 1
fi
echo "PASS: ClamAV executable, recent signature files and harmless scan"
