#!/usr/bin/env python3
"""
Xboard Lite NoBrand companion.

Phase 4 scope:
- Pull declarative NoBrand desired state from Xboard machine API.
- Reconcile one NoBrand-managed Mieru node per machine.
- Reconcile multiple Snell v5 logical nodes as isolated per-user instances.
- Manage only reserved Xboard namespaces (xb<user_id>, xbn<node_id>u<user_id>).
- Report per-user display endpoints and Mieru traffic back to Xboard.

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
import uuid
from dataclasses import dataclass
from typing import Any

VERSION = "0.4.0"
MANAGED_USER_RE = re.compile(r"^xb[1-9][0-9]*$")
MANAGED_SNELL_RE = re.compile(r"^xbn([1-9][0-9]*)u([1-9][0-9]*)$")
ALLOWED_TRANSPORTS = {"TCP", "UDP"}
ALLOWED_PROFILES = {"iplc", "balanced", "stealth"}
ALLOWED_MULTIPLEXING = {"off", "low", "middle", "high"}
ALLOWED_HANDSHAKES = {"no-wait", "standard"}

MITA_BIN = "/usr/local/lib/nobrand-oneclick/bin/mita"
MITA_INSTANCES_DIR = "/etc/mita/instances"
MITA_INSTANCE_RUN_DIR = "/run/mita-instances"
SNELL_STATE_DIR = "/var/lib/nobrand-oneclick/snell/instances"

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


def parse_mita_traffic_metrics(raw: str) -> tuple[int, int]:
    start = raw.find("{")
    end = raw.rfind("}")
    if start < 0 or end <= start:
        raise RuntimeError("Mita metrics returned no JSON")

    try:
        payload = json.loads(raw[start:end + 1])
    except Exception as exc:
        raise RuntimeError("Mita metrics returned invalid JSON") from exc

    traffic = payload.get("traffic")
    if not isinstance(traffic, dict):
        raise RuntimeError("Mita metrics has no traffic group")

    upload = traffic.get("UploadBytes")
    download = traffic.get("DownloadBytes")
    if not isinstance(upload, int) or isinstance(upload, bool) or upload < 0:
        raise RuntimeError("Mita UploadBytes is invalid")
    if not isinstance(download, int) or isinstance(download, bool) or download < 0:
        raise RuntimeError("Mita DownloadBytes is invalid")

    return upload, download


def read_instance_traffic_metrics(instance_id: str) -> tuple[int, int]:
    if not re.fullmatch(r"u[0-9a-f]{16}", instance_id):
        raise RuntimeError("invalid NoBrand Mieru instance id")

    config_path = os.path.join(MITA_INSTANCES_DIR, instance_id, "server.json")
    socket_path = os.path.join(MITA_INSTANCE_RUN_DIR, instance_id + ".sock")

    if not os.path.isfile(MITA_BIN):
        raise RuntimeError("managed Mita runtime is unavailable")
    if not os.path.isfile(config_path):
        raise RuntimeError(f"Mita config is unavailable for {instance_id}")
    if not os.path.exists(socket_path):
        raise RuntimeError(f"Mita management socket is unavailable for {instance_id}")

    env = os.environ.copy()
    env.update({
        "MITA_CONFIG_JSON_FILE": config_path,
        "MITA_UDS_PATH": socket_path,
        "MITA_LOG_NO_TIMESTAMP": "1",
    })

    proc = subprocess.run(
        [MITA_BIN, "get", "metrics"],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        encoding="utf-8",
        errors="replace",
        timeout=30,
        check=False,
        env=env,
    )

    if proc.returncode != 0:
        raise RuntimeError(f"Mita metrics failed for {instance_id}: rc={proc.returncode}")

    raw = (proc.stdout or "") + "\n" + (proc.stderr or "")
    try:
        return parse_mita_traffic_metrics(raw)
    except RuntimeError as exc:
        raise RuntimeError(f"{exc} for {instance_id}") from exc


def collect_traffic_readings(bindings: list[dict[str, Any]]) -> list[dict[str, Any]]:
    readings: list[dict[str, Any]] = []

    for binding in bindings:
        if not bool(binding.get("enabled", True)):
            continue

        instance_id = str(binding.get("instance_id") or "")
        if not instance_id:
            continue

        upload, download = read_instance_traffic_metrics(instance_id)
        readings.append({
            "user_id": int(binding["user_id"]),
            "instance_id": instance_id,
            "upload_bytes": upload,
            "download_bytes": download,
        })

    return readings


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

    node_type = str(node.get("type") or "")
    expected_node_id = int(node.get("id") or 0)
    result: dict[str, dict[str, Any]] = {}

    for item in users:
        if not isinstance(item, dict):
            continue

        name = str(item.get("remote_user") or "")
        if node_type == "mieru":
            if not MANAGED_USER_RE.fullmatch(name):
                raise RuntimeError("panel returned an invalid reserved Mieru username")
        elif node_type == "snell":
            match = MANAGED_SNELL_RE.fullmatch(name)
            if not match or int(match.group(1)) != expected_node_id:
                raise RuntimeError("panel returned an invalid reserved Snell instance name")
        else:
            raise RuntimeError(f"unsupported desired user protocol: {node_type}")

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


def snell_platform_supported() -> bool:
    machine = os.uname().machine.lower()
    return machine in {"x86_64", "amd64", "aarch64", "arm64"}


def load_snell_states() -> dict[str, dict[str, Any]]:
    result: dict[str, dict[str, Any]] = {}
    if not os.path.isdir(SNELL_STATE_DIR):
        return result

    for entry in os.scandir(SNELL_STATE_DIR):
        if not entry.is_file(follow_symlinks=False) or not entry.name.endswith(".json"):
            continue

        try:
            with open(entry.path, "r", encoding="utf-8") as fh:
                state = json.load(fh)
        except Exception:
            continue

        if not isinstance(state, dict) or state.get("protocol") != "snell":
            continue

        name = str(state.get("name") or "")
        if not MANAGED_SNELL_RE.fullmatch(name):
            continue

        instance_id = str(state.get("instance_id") or "")
        if not re.fullmatch(r"s[0-9a-f]{16}", instance_id):
            continue

        result[name] = state

    return result


def delete_snell_instance(name: str) -> None:
    if not MANAGED_SNELL_RE.fullmatch(name):
        raise RuntimeError("refusing to delete non-Xboard Snell instance")
    run_nb(["snell", "remove", "--name", name, "-y"])
    log(f"removed managed Snell instance {name}")


def install_snell_instance(node: dict[str, Any], desired: dict[str, Any]) -> None:
    if not snell_platform_supported():
        raise RuntimeError(
            f"Snell v5 is unsupported on architecture {os.uname().machine}"
        )

    name = str(desired["remote_user"])
    if not MANAGED_SNELL_RE.fullmatch(name):
        raise RuntimeError("invalid managed Snell instance name")

    protocol_settings = node.get("protocol_settings") or {}
    version = int(protocol_settings.get("version") or 5)
    if version != 5:
        raise RuntimeError("NoBrand companion currently supports Snell v5 only")
    if bool(protocol_settings.get("quic", False)):
        raise RuntimeError("Snell v5 QUIC Proxy is not enabled by the companion")

    args = [
        "snell", "install",
        "--name", name,
        "--version", "5",
        "--psk", str(desired["password"]),
        "--quic", "off",
        "--advertise-auto",
        "-y",
    ]

    ingress = str(runtime_settings(node).get("ingress_profile") or "").strip()
    if ingress:
        args.extend(["--ingress-profile", ingress])

    run_nb(args)
    log(f"installed managed Snell v5 instance {name}")


def set_snell_endpoint(name: str, host: str, port: int) -> None:
    if not MANAGED_SNELL_RE.fullmatch(name):
        raise RuntimeError("invalid managed Snell instance name")
    if not host or not 1 <= port <= 65535:
        raise RuntimeError("invalid Snell display endpoint")

    run_nb([
        "snell", "set-endpoint",
        "--name", name,
        "--advertise-host", host,
        "--advertise-port", str(port),
        "-y",
    ])


def snell_state_needs_recreate(
    node: dict[str, Any],
    desired: dict[str, Any],
    current: dict[str, Any],
) -> bool:
    if int(current.get("version") or 0) != 5:
        return True
    if bool(current.get("quic_proxy_enabled", False)):
        return True
    if str(current.get("psk") or "") != str(desired["password"]):
        return True

    wanted_ingress = str(runtime_settings(node).get("ingress_profile") or "").strip()
    current_ingress = str(current.get("ingress_profile_id") or "").strip()
    if wanted_ingress and current_ingress != wanted_ingress:
        return True

    return False


def reconcile_snell_node(
    node: dict[str, Any],
    all_states: dict[str, dict[str, Any]],
) -> list[dict[str, Any]]:
    desired = desired_users(node)
    node_id = int(node["id"])

    # The entire xbn<node>u<user> namespace is reserved for this logical node.
    for name in sorted(list(all_states)):
        match = MANAGED_SNELL_RE.fullmatch(name)
        if match and int(match.group(1)) == node_id and name not in desired:
            delete_snell_instance(name)
            all_states.pop(name, None)

    for name in sorted(desired):
        wanted = desired[name]
        current = all_states.get(name)

        if current is not None and snell_state_needs_recreate(node, wanted, current):
            delete_snell_instance(name)
            all_states.pop(name, None)
            current = None

        if current is None:
            install_snell_instance(node, wanted)
            all_states = load_snell_states()
            current = all_states.get(name)
            if current is None:
                raise RuntimeError(f"Snell install completed but state is unavailable for {name}")

        listen_port = int(current.get("listen_port") or 0)
        if not 1 <= listen_port <= 65535:
            raise RuntimeError(f"invalid Snell listen port for {name}")

        desired_host = str(runtime_settings(node).get("advertise_host") or node.get("host") or "")
        advertise_mode = str(current.get("advertise_mode") or "auto")
        current_host = str(current.get("advertise_host") or "")
        current_port = int(current.get("advertise_port") or 0) if current.get("advertise_port") else 0

        if desired_host and (
            advertise_mode != "custom"
            or current_host != desired_host
            or current_port != listen_port
        ):
            set_snell_endpoint(name, desired_host, listen_port)
            all_states = load_snell_states()
            current = all_states.get(name) or current

    bindings: list[dict[str, Any]] = []
    latest = load_snell_states()
    for name, wanted in desired.items():
        current = latest.get(name)
        if current is None:
            continue

        listen_port = int(current.get("listen_port") or 0)
        display_port = int(current.get("advertise_port") or listen_port)
        display_host = str(
            current.get("advertise_host")
            or runtime_settings(node).get("advertise_host")
            or node.get("host")
            or ""
        )

        if not 1 <= display_port <= 65535:
            continue

        bindings.append({
            "node_id": node_id,
            "user_id": int(wanted["user_id"]),
            "remote_user": name,
            "instance_id": str(current.get("instance_id") or "") or None,
            "display_host": display_host or None,
            "display_port": display_port,
            "transport": "TCP",
            "enabled": bool(current.get("enabled", True)),
            "runtime_meta": {
                "version": int(current.get("version") or 5),
                "quic_proxy_enabled": bool(current.get("quic_proxy_enabled", False)),
                "runtime_version": current.get("runtime_version"),
                "runtime_status": current.get("runtime_status"),
                "updated_at": current.get("updated_at"),
            },
        })

    return bindings


def reconcile_once(cfg: Config) -> dict[str, int]:
    desired = api_post(cfg, "/api/v2/server/machine/nobrand-nodes")
    nodes = desired.get("nodes")
    if not isinstance(nodes, list):
        raise RuntimeError("panel desired state has no nodes array")

    mieru_nodes = [
        node for node in nodes
        if isinstance(node, dict) and str(node.get("type") or "") == "mieru"
    ]
    snell_nodes = [
        node for node in nodes
        if isinstance(node, dict) and str(node.get("type") or "") == "snell"
    ]

    if len(mieru_nodes) > 1:
        raise RuntimeError(
            "only one NoBrand Mieru logical node is supported per machine"
        )

    mieru_bindings: list[dict[str, Any]] = []
    snell_bindings: list[dict[str, Any]] = []

    if mieru_nodes:
        mieru_bindings.extend(reconcile_mieru(mieru_nodes[0]))

    snell_states = load_snell_states()
    desired_snell_names: set[str] = set()
    for node in snell_nodes:
        desired_snell_names.update(desired_users(node).keys())
        snell_bindings.extend(reconcile_snell_node(node, snell_states))
        snell_states = load_snell_states()

    # If an Xboard Snell logical node was deleted, its reserved instances no
    # longer appear in desired state. Remove only our globally reserved xbn*
    # namespace; manual NoBrand Snell names remain untouched.
    for name in sorted(snell_states):
        if MANAGED_SNELL_RE.fullmatch(name) and name not in desired_snell_names:
            delete_snell_instance(name)

    bindings = mieru_bindings + snell_bindings
    traffic_readings: list[dict[str, Any]] = []
    managed_node_ids = sorted({
        int(node["id"])
        for node in (mieru_nodes + snell_nodes)
    })

    if managed_node_ids:
        api_post(cfg, "/api/v2/server/machine/nobrand-bindings", {
            "node_ids": managed_node_ids,
            "bindings": bindings,
        })

    if mieru_bindings:
        traffic_readings = collect_traffic_readings(mieru_bindings)
        if traffic_readings and mieru_nodes:
            api_post(cfg, "/api/v2/server/machine/nobrand-traffic", {
                "node_id": int(mieru_nodes[0]["id"]),
                "report_id": uuid.uuid4().hex,
                "observed_at": int(time.time()),
                "readings": traffic_readings,
            })

    managed_nodes = len(mieru_nodes) + len(snell_nodes)
    managed_users = sum(
        len(node.get("users") or [])
        for node in (mieru_nodes + snell_nodes)
        if isinstance(node.get("users"), list)
    )

    return {
        "managed_nodes": managed_nodes,
        "managed_users": managed_users,
        "bindings": len(bindings),
        "traffic_readings": len(traffic_readings),
    }


def report_status(
    cfg: Config,
    state: str,
    *,
    message: str | None = None,
    stats: dict[str, int] | None = None,
    reconcile_ms: int = 0,
) -> None:
    stats = stats or {}
    payload = {
        "state": state,
        "version": VERSION,
        "message": (message or "")[:900] or None,
        "managed_nodes": int(stats.get("managed_nodes", 0)),
        "managed_users": int(stats.get("managed_users", 0)),
        "bindings": int(stats.get("bindings", 0)),
        "traffic_readings": int(stats.get("traffic_readings", 0)),
        "reconcile_ms": max(0, int(reconcile_ms)),
    }
    api_post(cfg, "/api/v2/server/machine/nobrand-status", payload)


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
        started = time.monotonic()
        try:
            stats = reconcile_once(cfg)
            report_status(
                cfg,
                "ok",
                stats=stats,
                reconcile_ms=int((time.monotonic() - started) * 1000),
            )
            return 0
        except Exception as exc:
            try:
                report_status(
                    cfg,
                    "error",
                    message=str(exc),
                    reconcile_ms=int((time.monotonic() - started) * 1000),
                )
            except Exception:
                pass
            raise

    log(f"starting v{VERSION}; poll_interval={cfg.poll_interval}s")

    while not STOP:
        started = time.monotonic()
        try:
            stats = reconcile_once(cfg)
            elapsed_ms = int((time.monotonic() - started) * 1000)
            try:
                report_status(cfg, "ok", stats=stats, reconcile_ms=elapsed_ms)
            except Exception as status_exc:
                log(f"status report failed: {status_exc}")
        except Exception as exc:
            elapsed_ms = int((time.monotonic() - started) * 1000)
            try:
                report_status(cfg, "error", message=str(exc), reconcile_ms=elapsed_ms)
            except Exception:
                pass
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
