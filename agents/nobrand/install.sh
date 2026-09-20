#!/usr/bin/env bash
set -Eeuo pipefail

AGENT_URL="https://raw.githubusercontent.com/ludehonghaha/Xboard/xboard-lite-v1/agents/nobrand/nobrand_agent.py"
AGENT_BIN="/usr/local/bin/xboard-nobrand-agent"
CONFIG_FILE="/etc/xboard-nobrand-agent.json"
SERVICE_FILE="/etc/systemd/system/xboard-nobrand-agent.service"

PANEL=""
MACHINE_ID=""
TOKEN=""
POLL_INTERVAL="30"

usage() {
  cat <<'EOF'
Usage:
  sudo bash install.sh --panel https://panel.example.com --machine-id 1 --token TOKEN [--poll-interval 30]
EOF
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --panel) PANEL="${2:-}"; shift 2 ;;
    --machine-id) MACHINE_ID="${2:-}"; shift 2 ;;
    --token) TOKEN="${2:-}"; shift 2 ;;
    --poll-interval) POLL_INTERVAL="${2:-}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $1" >&2; usage; exit 2 ;;
  esac
done

[ "$(id -u)" -eq 0 ] || { echo "Run as root" >&2; exit 2; }
command -v python3 >/dev/null 2>&1 || { echo "python3 is required" >&2; exit 1; }
command -v curl >/dev/null 2>&1 || { echo "curl is required" >&2; exit 1; }
command -v systemctl >/dev/null 2>&1 || { echo "systemd is required for Phase 2 agent" >&2; exit 1; }
command -v nobrand >/dev/null 2>&1 || { echo "nobrand manager must be installed first" >&2; exit 1; }

[ -n "$PANEL" ] || { echo "--panel is required" >&2; exit 2; }
[[ "$MACHINE_ID" =~ ^[1-9][0-9]*$ ]] || { echo "--machine-id must be positive" >&2; exit 2; }
[ -n "$TOKEN" ] || { echo "--token is required" >&2; exit 2; }
[[ "$POLL_INTERVAL" =~ ^[0-9]+$ ]] || { echo "--poll-interval must be numeric" >&2; exit 2; }
[ "$POLL_INTERVAL" -ge 10 ] && [ "$POLL_INTERVAL" -le 3600 ] || {
  echo "--poll-interval must be 10-3600" >&2
  exit 2
}

tmp="$(mktemp /tmp/xboard-nobrand-agent.XXXXXX)"
trap 'rm -f "$tmp"' EXIT
curl -fsSL "$AGENT_URL" -o "$tmp"
python3 -m py_compile "$tmp"
install -o root -g root -m 0755 "$tmp" "$AGENT_BIN"

python3 - "$CONFIG_FILE" "$PANEL" "$MACHINE_ID" "$TOKEN" "$POLL_INTERVAL" <<'PY'
import json, os, sys, tempfile
path, panel, machine_id, token, poll = sys.argv[1:]
payload = {
    "panel_url": panel.rstrip("/"),
    "machine_id": int(machine_id),
    "token": token,
    "poll_interval": int(poll),
    "timeout": 20,
}
directory = os.path.dirname(path)
os.makedirs(directory, exist_ok=True)
fd, tmp = tempfile.mkstemp(prefix=".xboard-nobrand.", dir=directory)
try:
    with os.fdopen(fd, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, separators=(",", ":"))
        fh.write("\n")
    os.chmod(tmp, 0o600)
    os.replace(tmp, path)
finally:
    try:
        os.unlink(tmp)
    except FileNotFoundError:
        pass
PY

cat >"$SERVICE_FILE" <<EOF
[Unit]
Description=Xboard Lite NoBrand Companion
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
Group=root
ExecStart=$AGENT_BIN --config $CONFIG_FILE
Restart=always
RestartSec=5
UMask=0077
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF

chmod 0644 "$SERVICE_FILE"
systemctl daemon-reload
systemctl enable --now xboard-nobrand-agent.service

"$AGENT_BIN" --version
systemctl --no-pager --full status xboard-nobrand-agent.service || true

echo "Xboard NoBrand companion installed."
