#!/usr/bin/env bash
# Called from Orbit-managed runtime units when crash reporting is enabled.
set -euo pipefail

UNIT="${1:-unknown}"
CODE="${2:-0}"
STATUS="${3:-unknown}"
NOW="$(date -Iseconds)"
EVENT_ID="$(printf '%s:%s:%s:%s:%s' "$(hostname)" "${UNIT}" "${CODE}" "${STATUS}" "${NOW}" | sha256sum | awk '{print $1}')"

GATEWAY_ENDPOINT_FILE="/etc/orbit/gateway-endpoint"

if [[ ! -r "$GATEWAY_ENDPOINT_FILE" ]]; then
    exit 0
fi

ENDPOINT="$(cat "$GATEWAY_ENDPOINT_FILE")"
CA_CERT="/etc/orbit/gateway-ca.pem"

CURL_ARGS=(-sS --max-time 5 --retry 3 --retry-delay 2
    -H "Content-Type: application/json"
    -X POST
    "${ENDPOINT}/api/events/process"
    -d "{\"event_id\":\"${EVENT_ID}\",\"event\":\"crashed\",\"unit\":\"${UNIT}\",\"exit_code\":${CODE},\"exit_status\":\"${STATUS}\",\"at\":\"${NOW}\"}"
)

if [[ -r "$CA_CERT" ]]; then
    CURL_ARGS=(--cacert "$CA_CERT" "${CURL_ARGS[@]}")
else
    CURL_ARGS=(--insecure "${CURL_ARGS[@]}")
fi

curl "${CURL_ARGS[@]}" || true
