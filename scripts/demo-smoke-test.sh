#!/usr/bin/env bash

set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8000}"
DEVICE_FINGERPRINT="${DEVICE_FINGERPRINT:-}"
LICENSE_KEY="${LICENSE_KEY:-}"

if [[ -z "${DEVICE_FINGERPRINT}" || -z "${LICENSE_KEY}" ]]; then
  echo "Set DEVICE_FINGERPRINT and LICENSE_KEY (columna license_key en admin Licencias o respuesta de register-device)."
  echo "Example: DEVICE_FINGERPRINT=POS-CENTRO-01-FP LICENSE_KEY=<uuid-license-key> $0"
  exit 1
fi

echo "== POS Demo Smoke Test =="
echo "Base URL: ${BASE_URL}"
echo "Device fingerprint: ${DEVICE_FINGERPRINT}"
echo "License key: ${LICENSE_KEY}"
echo

login_payload=$(
  cat <<EOF
{"device_fingerprint":"${DEVICE_FINGERPRINT}","license_key":"${LICENSE_KEY}"}
EOF
)

echo "1) Login..."
login_response="$(curl -sS -X POST "${BASE_URL}/api/auth/login" -H "Content-Type: application/json" -H "Accept: application/json" -d "${login_payload}")"
token="$(printf "%s" "${login_response}" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["token"] ?? "";')"

if [[ -z "${token}" ]]; then
  echo "Login failed. Response:"
  echo "${login_response}"
  exit 1
fi

echo "Login OK"
echo

auth_headers=(
  -H "Authorization: Bearer ${token}"
)

echo "2) Me..."
curl -sS "${BASE_URL}/api/auth/me" "${auth_headers[@]}" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo json_encode($d, JSON_PRETTY_PRINT).PHP_EOL;'
echo

echo "3) Sync pull (catálogo)..."
curl -sS -G "${BASE_URL}/api/sync/pull" --data-urlencode "device_fingerprint=${DEVICE_FINGERPRINT}" "${auth_headers[@]}" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo json_encode($d, JSON_PRETTY_PRINT).PHP_EOL;'
echo

echo "4) Dashboard..."
curl -sS "${BASE_URL}/api/reports/dashboard" "${auth_headers[@]}" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo json_encode($d, JSON_PRETTY_PRINT).PHP_EOL;'
echo

echo "5) Reporte por localidad..."
curl -sS "${BASE_URL}/api/reports/by-location" "${auth_headers[@]}" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo json_encode($d, JSON_PRETTY_PRINT).PHP_EOL;'
echo

echo "Smoke test completed successfully."
