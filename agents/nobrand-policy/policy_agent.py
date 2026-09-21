#!/usr/bin/env python3
"""
Xboard Lite -> NoBrand Mieru policy bridge.

This agent deliberately does NOT manage protocol lifecycle. It never installs,
reconfigures, upgrades, removes, creates or deletes NoBrand protocol users.

Allowed mutations are limited to an already-existing NoBrand Mieru user:
- quota
- bandwidth
- expiry
- enable / disable
"""

from __future__ import annotations

import argparse
import json
import os
import signal
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any

VERSION = "0.1.0"
NOBRAND_BIN = "/usr/local/bin/nobrand"
STOP = False


def log(message: str) -> None:
    print(f"[xboard-nobrand-policy] {message}", flush=True)


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
    body: dict[str, Any] = {
        "machine_id": cfg.machine_id,
        "token": cfg.token,
    }
    if payload:
        body.update(payload)

    request = urllib.request.Request(
        cfg.panel_url + path,
        data=json.dumps(body, separators=(",", ":")).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": f"xboard-nobrand-policy/{VERSION}",
        },
        method="POST",
    )

    try:
        with urllib.request.urlopen(request, timeout=cfg.timeout) as response:
            raw = response.read()
    except urllib.error.HTTPError as exc:
        raise RuntimeError(f"panel HTTP {exc.code} for {path}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"panel connection failed for {path}: {exc.reason}") from exc

    try:
        decoded = json.loads(raw.decode("utf-8"))
    except Exception as exc:
        raise RuntimeError(f"panel returned invalid JSON for {path}") from exc

    if not isinstance(decoded, dict):
        raise RuntimeError(f"panel returned non-object JSON for {path}")

    return decoded


def run_nobrand(args: list[str], *, allow_fail: bool = False) -> subprocess.CompletedProcess[str]:
    allowed = {
        ("mieru", "user-export"),
        ("mieru", "user-set-quota"),
        ("mieru", "user-set-rate"),
        ("mieru", "user-set-expire"),
        ("mieru", "user-enable"),
        ("mieru", "user-disable"),
    }

    if len(args) < 2 or tuple(args[:2]) not in allowed:
        raise RuntimeError("refusing non-policy NoBrand action")

    if not os.path.isfile(NOBRAND_BIN):
        raise RuntimeError("NoBrand manager is not installed")

    proc = subprocess.run(
        [NOBRAND_BIN, *args],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        encoding="utf-8",
        errors="replace",
        timeout=180,
        check=False,
    )

    if proc.returncode != 0 and not allow_fail:
        # Do not echo args/stdout/stderr: they can contain local account data.
        raise RuntimeError(f"NoBrand policy action failed: {args[1]} rc={proc.returncode}")

    return proc


def export_users() -> dict[str, dict[str, Any]]:
    proc = run_nobrand(["mieru", "user-export"])
    try:
        payload = json.loads(proc.stdout)
    except Exception as exc:
        raise RuntimeError("NoBrand user-export returned invalid JSON") from exc

    if not isinstance(payload, dict):
        raise RuntimeError("NoBrand user-export returned non-object JSON")

    users = payload.get("users")
    if not isinstance(users, list):
        raise RuntimeError("NoBrand user-export has no users array")

    result: dict[str, dict[str, Any]] = {}
    for item in users:
        if isinstance(item, dict) and item.get("name"):
            result[str(item["name"])] = item
    return result


def safe_remote_state(item: dict[str, Any]) -> dict[str, Any]:
    return {
        "enabled": bool(item.get("enabled", True)),
        "quota_mb": max(0, int(item.get("quota_mb") or 0)),
        "quota_days": max(0, int(item.get("quota_days") or 0)),
        "quota_mode": str(item.get("quota_mode") or "rolling"),
        "expire_at": str(item.get("expire_at") or ""),
        "bandwidth_mbps": max(0, int(item.get("bandwidth_mbps") or 0)),
        "instance_id": str(item.get("instance_id") or "") or None,
        "port": int(item.get("port") or 0) or None,
    }


def normalize_expire(value: str) -> str:
    value = value.strip().lower()
    return "" if value in {"", "0", "never", "none"} else value


def reconcile_policy(policy: dict[str, Any], users: dict[str, dict[str, Any]]) -> dict[str, Any]:
    binding_id = int(policy.get("binding_id") or 0)
    remote_user = str(policy.get("remote_user") or "")

    if binding_id <= 0 or not remote_user:
        return {
            "binding_id": max(1, binding_id),
            "ok": False,
            "message": "invalid policy payload",
        }

    current = users.get(remote_user)
    if current is None:
        return {
            "binding_id": binding_id,
            "ok": False,
            "message": "mapped NoBrand Mieru user does not exist",
        }

    desired_quota = max(0, int(policy.get("quota_mb") or 0))
    desired_days = max(1, int(policy.get("quota_days") or 30))
    desired_mode = str(policy.get("quota_mode") or "calendar")
    desired_bw = max(0, int(policy.get("bandwidth_mbps") or 0))
    desired_expire = str(policy.get("expire") or "0")
    desired_enabled = bool(policy.get("enabled", False))

    if desired_mode not in {"rolling", "calendar"}:
        return {
            "binding_id": binding_id,
            "ok": False,
            "message": "unsupported quota mode",
        }

    current_quota = max(0, int(current.get("quota_mb") or 0))
    current_days = max(0, int(current.get("quota_days") or 0))
    current_mode = str(current.get("quota_mode") or "rolling")
    quota_drift = current_quota != desired_quota or current_mode != desired_mode
    if desired_mode == "rolling":
        quota_drift = quota_drift or current_days != desired_days

    if quota_drift:
        run_nobrand([
            "mieru", "user-set-quota",
            "--user", remote_user,
            "--quota-mb", str(desired_quota),
            "--quota-days", str(desired_days),
            "--quota-mode", desired_mode,
            "-y",
        ])

    if max(0, int(current.get("bandwidth_mbps") or 0)) != desired_bw:
        run_nobrand([
            "mieru", "user-set-rate",
            "--user", remote_user,
            "--bandwidth", str(desired_bw),
            "-y",
        ])

    current_expire = normalize_expire(str(current.get("expire_at") or ""))
    wanted_expire = normalize_expire(desired_expire)
    if current_expire != wanted_expire:
        run_nobrand([
            "mieru", "user-set-expire",
            "--user", remote_user,
            "--expire", desired_expire,
            "-y",
        ])

    if bool(current.get("enabled", True)) != desired_enabled:
        run_nobrand([
            "mieru",
            "user-enable" if desired_enabled else "user-disable",
            remote_user,
            "-y",
        ])

    refreshed = export_users().get(remote_user)
    if refreshed is None:
        return {
            "binding_id": binding_id,
            "ok": False,
            "message": "NoBrand user disappeared during policy reconciliation",
        }

    return {
        "binding_id": binding_id,
        "ok": True,
        "message": "policy synchronized",
        "remote_state": safe_remote_state(refreshed),
    }


def reconcile_once(cfg: Config) -> list[dict[str, Any]]:
    desired = api_post(cfg, "/api/v2/server/machine/nobrand-policies")
    policies = desired.get("policies")
    if not isinstance(policies, list):
        raise RuntimeError("panel policy response has no policies array")

    results: list[dict[str, Any]] = []

    if not policies:
        api_post(cfg, "/api/v2/server/machine/nobrand-policy-status", {
            "agent_version": VERSION,
            "results": [],
        })
        return results

    users = export_users()

    for policy in policies:
        if not isinstance(policy, dict):
            continue
        try:
            result = reconcile_policy(policy, users)
        except Exception as exc:
            result = {
                "binding_id": max(1, int(policy.get("binding_id") or 1)),
                "ok": False,
                "message": str(exc)[:900],
            }
        results.append(result)
        # Policy actions can rebuild local Mieru state. Refresh once per user
        # so the next mapping never relies on stale exported state.
        users = export_users()

    api_post(cfg, "/api/v2/server/machine/nobrand-policy-status", {
        "agent_version": VERSION,
        "results": results,
    })
    return results


def main() -> int:
    parser = argparse.ArgumentParser(description="Xboard NoBrand Mieru policy agent")
    parser.add_argument("--config", default="/etc/xboard-nobrand-policy.json")
    parser.add_argument("--once", action="store_true")
    parser.add_argument("--version", action="store_true")
    args = parser.parse_args()

    if args.version:
        print(VERSION)
        return 0

    if os.geteuid() != 0:
        print("xboard-nobrand-policy must run as root", file=sys.stderr)
        return 2

    cfg = load_config(args.config)
    signal.signal(signal.SIGTERM, stop_handler)
    signal.signal(signal.SIGINT, stop_handler)

    if args.once:
        reconcile_once(cfg)
        return 0

    log(f"starting v{VERSION}; policy-only mode; poll={cfg.poll_interval}s")

    while not STOP:
        started = time.monotonic()
        try:
            results = reconcile_once(cfg)
            errors = sum(1 for item in results if not item.get("ok"))
            log(f"reconciled {len(results)} mappings; errors={errors}")
        except Exception as exc:
            log(f"reconcile failed: {exc}")

        elapsed = time.monotonic() - started
        deadline = time.monotonic() + max(1.0, cfg.poll_interval - elapsed)
        while not STOP and time.monotonic() < deadline:
            time.sleep(min(1.0, max(0.1, deadline - time.monotonic())))

    log("stopped")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
