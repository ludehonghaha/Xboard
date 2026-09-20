#!/usr/bin/env python3
"""
Xboard Lite NoBrand companion.

Phase 2 scope:
- Pull declarative NoBrand desired state from Xboard machine API.
- Reconcile one NoBrand-managed Mieru node per machine.
- Manage only Xboard-owned users named xb<user_id>.
- Report per-user display endpoints back to Xboard.

Security:
- No shell=True.
- No remote command strings are accepted.
- Credentials are never written to normal logs.
- Manual NoBrand users that do not match an Xboard desired remote_user are
  left untouched unless they use the reserved xb<id> namespace.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import signal
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any

VERSION = "0.2.0"
MANAGED_USER_RE = re.compile(r"^xb[1-9][0-9]*$")
ALLOWED_TRANSPORTS = {"TCP", "UDP"}
ALLOWED_PROFILES = {"iplc", "balanced", "stealth"}
ALLOWED_MULTIPLEXING = {"off", "low", "middle", "high"}
ALLOWED_HANDSHAKES = {"no-wait", "standard"}

STOP = False


def log(message: str) -> None:
    print(f"[xboard-nobrand] {message}", flush=True)


def stop_handler(_signum: int, _frame: Any) -> None:
    global STOP
    STOP = True


@dataclass(frozen=True)
class Config:
    panel_url: str
    machine_id: int
    token: str
    poll_interval: int = 30
    timeout: int = 20


def load_config(path: str) -> Config:
    with open(path, "r", encoding="utf-8") as fh:
        raw = json.load(fh)

    panel_url = str(raw.get("panel_url") or "").strip().rstrip("/")
    machine_id = int(raw.get("machine_id") or 0)
    token = str(raw.get("token") or "")
    poll_interval = max(10, min(3600, int(raw.get("poll_interval") or 30)))
    timeout = max(5, min(120, int(raw.get("timeout") or 20)))

    parsed = urllib.parse.urlparse(panel_url)
    if parsed.scheme not in {"http", "https"} or not parsed.netloc:
        raise ValueError("panel_url must be an absolute http(s) URL")
    if machine_id <= 0:
        raise ValueError("machine_id must be positive")
    if not token:
        raise ValueError("token is required")

    return Config(panel_url, machine_id, token, poll_interval, timeout)


def api_post(cfg: Config, path: str, payload: dict[str, Any] | None = None) -> dict[str, Any]:
    body = {
        "machine_id": cfg.machine_id,
        "token": cfg.token,
    }
    if payload:
        body.update(payload)

    req = urllib.request.Request(
        cfg.panel_url + path,
        data=json.dumps(body, separators=(",", ":")).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": f"xboard-nobrand-agent/{VERSION}",
        },
        method="POST",
    )

    try:
        with urllib.request.urlopen(req, timeout=cfg.timeout) as resp:
            data = resp.read()
    except urllib.error.HTTPError as exc:
        raise RuntimeError(f"panel HTTP {exc.code} for {path}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"panel connection failed for {path}: {exc.reason}") from exc

    try:
        decoded = json.loads(data.decode("utf-8"))
    except Exception as exc:
        raise RuntimeError(f"panel returned invalid JSON for {path}") from exc

    if not isinstance(decoded, dict):
        raise RuntimeError(f"panel returned non-object JSON for {path}")
    return decoded


def run_nb(args: list[str], *, allow_fail: bool = False) -> subprocess.CompletedProcess[str]:
    proc = subprocess.run(
        ["/usr/local/bin/nobrand", *args],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        encoding="utf-8",
        errors="replace",
        timeout=180,
        check=False,
    )

    if proc.returncode != 0 and not allow_fail:
        raise RuntimeError(
            f"nobrand action failed: action={args[:2]!r} rc={proc.returncode}"
        )
    return proc


def export_mieru_state() -> dict[str, Any] | None:
    proc = run_nb(["mieru", "user-export"], allow_fail=True)
    if proc.returncode != 0:
        return None

    try:
        state = json.loads(proc.stdout)
    except Exception as exc:
        raise RuntimeError("nobrand user-export returned invalid JSON") from exc

    if not isinstance(state, dict):
        raise RuntimeError("nobrand user-export returned non-object JSON")
    return state


def runtime_settings(node: dict[str, Any]) -> dict[str, Any]:
    value = node.get("runtime_driver_settings")
    return value if isinstance(value, dict) else {}


def normalize_transport(node: dict[str, Any]) -> str:
    value = str(((node.get("protocol_settings") or {}).get("transport") or "TCP")).upper()
    if value not in ALLOWED_TRANSPORTS:
        raise RuntimeError(f"unsupported Mieru transport: {value}")
    return value


def normalize_profile(node: dict[str, Any]) -> str:
    value = str(runtime_settings(node).get("profile") or "iplc").lower()
    if value not in ALLOWED_PROFILES:
        raise RuntimeError(f"unsupported NoBrand profile: {value}")
    return value


def normalize_mtu(node: dict[str, Any]) -> str:
    raw = runtime_settings(node).get("mtu", 1400)
    if isinstance(raw, str) and raw in {"safe", "auto"}:
        return raw
    value = int(raw)
    if not 1280 <= value <= 1500:
        raise RuntimeError("Mieru MTU must be safe/auto or 1280-1500")
    return str(value)


def normalize_multiplexing(node: dict[str, Any]) -> str:
    raw = str(runtime_settings(node).get("multiplexing") or "off")
    raw = raw.lower().replace("multiplexing_", "")
    if raw not in ALLOWED_MULTIPLEXING:
        raise RuntimeError(f"unsupported multiplexing mode: {raw}")
    return raw


def normalize_handshake(node: dict[str, Any]) -> str:
    raw = str(runtime_settings(node).get("handshake_mode") or "no-wait")
    raw = raw.lower().replace("handshake_", "").replace("_", "-")
    if raw not in ALLOWED_HANDSHAKES:
        raise RuntimeError(f"unsupported handshake mode: {raw}")
    return raw


def desired_users(node: dict[str, Any]) -> dict[str, dict[str, Any]]:
    users = node.get("users")
    if not isinstance(users, list):
        return {}

    result: dict[str, dict[str, Any]] = {}
    for item in users:
        if not isinstance(item, dict):
            continue
        name = str(item.get("remote_user") or "")
        if not MANAGED_USER_RE.fullmatch(name):
            raise RuntimeError("panel returned an invalid reserved NoBrand username")
        password = str(item.get("password") or "")
        if not password or len(password) > 256:
            raise RuntimeError("panel returned invalid NoBrand user credential")
        result[name] = item
    return result


def base_user_args(node: dict[str, Any], user: dict[str, Any]) -> list[str]:
    args = [
        "--user", str(user["remote_user"]),
        "--password", str(user["password"]),
        "--package", "unlimited",
        "--expire", str(user.get("expire") or "0"),
        "--bandwidth", str(max(0, int(user.get("bandwidth_mbps") or 0))),
    ]

    host = str(runtime_settings(node).get("advertise_host") or node.get("host") or "")
    if host:
        args.extend(["--advertise-host", host])

    ingress = str(runtime_settings(node).get("ingress_profile") or "").strip()
    if ingress:
        args.extend(["--ingress-profile", ingress])

    return args


def install_mieru(node: dict[str, Any], first_user: dict[str, Any]) -> None:
    args = [
        "mieru", "install", "-y",
        "--protocol", normalize_transport(node),
        "--profile", normalize_profile(node),
        "--mtu", normalize_mtu(node),
        "--multiplexing", normalize_multiplexing(node),
        "--handshake-mode", normalize_handshake(node),
    ]

    traffic_pattern = str(
        (node.get("protocol_settings") or {}).get("traffic_pattern") or ""
    ).strip().lower()
    if traffic_pattern:
        if traffic_pattern not in {"off", "conservative", "aggressive"}:
            raise RuntimeError("unsupported traffic pattern")
        args.extend(["--traffic-pattern", traffic_pattern])

    settings = runtime_settings(node)
    if bool(settings.get("pin_primary_port")):
        port = int(node.get("server_port") or 0)
        if not 1025 <= port <= 65535:
            raise RuntimeError("pin_primary_port requires server_port 1025-65535")
        args.extend(["--port", str(port)])

    args.extend(base_user_args(node, first_user))
    run_nb(args)
    log("installed NoBrand Mieru runtime and initial Xboard user")


def add_user(node: dict[str, Any], user: dict[str, Any]) -> None:
    run_nb(["mieru", "user-add", "-y", *base_user_args(node, user)])
    log(f"added managed Mieru user {user['remote_user']}")


def delete_user(name: str) -> None:
    run_nb(["mieru", "user-del", name, "-y"])
    log(f"removed managed Mieru user {name}")


def set_user_expire(name: str, expire: str) -> None:
    run_nb(["mieru", "user-set-expire", "-y", "--user", name, "--expire", expire])


def set_user_rate(name: str, bandwidth: int) -> None:
    run_nb([
        "mieru", "user-set-rate", "-y",
        "--user", name,
        "--bandwidth", str(max(0, bandwidth)),
    ])


def set_user_endpoint(name: str, host: str) -> None:
    if not host:
        return
    run_nb([
        "mieru", "user-set-endpoint", "-y",
        "--user", name,
        "--advertise-host", host,
    ])


def reconcile_existing_user(
    node: dict[str, Any],
    desired: dict[str, Any],
    current: dict[str, Any],
) -> bool:
    name = str(desired["remote_user"])

    if str(current.get("password") or "") != str(desired["password"]):
        delete_user(name)
        add_user(node, desired)
        return True

    changed = False

    desired_expire = str(desired.get("expire") or "0")
    current_expire = str(current.get("expire_at") or "")
    normalized_expire = "" if desired_expire in {"0", "never", "none"} else desired_expire
    if current_expire != normalized_expire:
        set_user_expire(name, desired_expire)
        changed = True

    desired_bw = max(0, int(desired.get("bandwidth_mbps") or 0))
    current_bw = max(0, int(current.get("bandwidth_mbps") or 0))
    if current_bw != desired_bw:
        set_user_rate(name, desired_bw)
        changed = True

    settings = runtime_settings(node)
    if bool(settings.get("sync_advertise_host", True)):
        desired_host = str(settings.get("advertise_host") or node.get("host") or "")
        current_host = str(current.get("advertise_host") or "")
        if desired_host and current_host != desired_host:
            set_user_endpoint(name, desired_host)
            changed = True

    return changed


def state_users(state: dict[str, Any]) -> dict[str, dict[str, Any]]:
    return {
        str(item.get("name") or ""): item
        for item in (state.get("users") or [])
        if isinstance(item, dict) and item.get("name")
    }


def reconcile_mieru(node: dict[str, Any]) -> list[dict[str, Any]]:
    desired = desired_users(node)
    state = export_mieru_state()

    if state is None:
        if not desired:
            return []
        first_name = sorted(desired)[0]
        install_mieru(node, desired[first_name])
        state = export_mieru_state()
        if state is None:
            raise RuntimeError("Mieru install completed but user state is unavailable")

    protocol = str(state.get("protocol") or normalize_transport(node)).upper()
    wanted_protocol = normalize_transport(node)
    if protocol != wanted_protocol:
        raise RuntimeError(
            f"NoBrand Mieru protocol drift: local={protocol} desired={wanted_protocol}"
        )

    local_users = state_users(state)

    for name in sorted(local_users):
        if MANAGED_USER_RE.fullmatch(name) and name not in desired:
            delete_user(name)

    state = export_mieru_state() or {"users": []}
    local_users = state_users(state)

    changed = False
    for name in sorted(desired):
        user = desired[name]
        current = local_users.get(name)
        if current is None:
            add_user(node, user)
            changed = True
            continue
        changed = reconcile_existing_user(node, user, current) or changed

    if changed:
        state = export_mieru_state()
        if state is None:
            raise RuntimeError("NoBrand state disappeared after reconciliation")

    local_users = state_users(state)

    bindings: list[dict[str, Any]] = []
    for name, wanted in desired.items():
        current = local_users.get(name)
        if current is None:
            continue

        port = int(current.get("advertise_port") or current.get("port") or 0)
        if not 1 <= port <= 65535:
            continue

        host = str(
            current.get("advertise_host")
            or runtime_settings(node).get("advertise_host")
            or node.get("host")
            or ""
        )

        bindings.append({
            "node_id": int(node["id"]),
            "user_id": int(wanted["user_id"]),
            "remote_user": name,
            "instance_id": str(current.get("instance_id") or "") or None,
            "display_host": host or None,
            "display_port": port,
            "transport": protocol,
            "enabled": bool(current.get("enabled", True)),
            "runtime_meta": {
                "package": current.get("package"),
                "bandwidth_mbps": int(current.get("bandwidth_mbps") or 0),
                "expire_at": current.get("expire_at") or "",
                "updated_at": current.get("updated_at"),
            },
        })

    return bindings


def reconcile_once(cfg: Config) -> None:
    desired = api_post(cfg, "/api/v2/server/machine/nobrand-nodes")
    nodes = desired.get("nodes")
    if not isinstance(nodes, list):
        raise RuntimeError("panel desired state has no nodes array")

    mieru_nodes = [
        node for node in nodes
        if isinstance(node, dict) and str(node.get("type") or "") == "mieru"
    ]

    if len(mieru_nodes) > 1:
        raise RuntimeError(
            "Phase 2 supports one NoBrand Mieru node per machine; split additional nodes across machines"
        )

    bindings: list[dict[str, Any]] = []
    if mieru_nodes:
        bindings.extend(reconcile_mieru(mieru_nodes[0]))

    if bindings:
        api_post(cfg, "/api/v2/server/machine/nobrand-bindings", {
            "bindings": bindings,
        })


def main() -> int:
    parser = argparse.ArgumentParser(description="Xboard NoBrand companion")
    parser.add_argument("--config", default="/etc/xboard-nobrand-agent.json")
    parser.add_argument("--once", action="store_true")
    parser.add_argument("--version", action="store_true")
    args = parser.parse_args()

    if args.version:
        print(VERSION)
        return 0

    if os.geteuid() != 0:
        print("xboard-nobrand-agent must run as root", file=sys.stderr)
        return 2

    cfg = load_config(args.config)

    signal.signal(signal.SIGTERM, stop_handler)
    signal.signal(signal.SIGINT, stop_handler)

    if args.once:
        reconcile_once(cfg)
        return 0

    log(f"starting v{VERSION}; poll_interval={cfg.poll_interval}s")

    while not STOP:
        started = time.monotonic()
        try:
            reconcile_once(cfg)
        except Exception as exc:
            log(f"reconcile failed: {exc}")

        elapsed = time.monotonic() - started
        sleep_for = max(1.0, cfg.poll_interval - elapsed)
        deadline = time.monotonic() + sleep_for
        while not STOP and time.monotonic() < deadline:
            time.sleep(min(1.0, max(0.1, deadline - time.monotonic())))

    log("stopped")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
