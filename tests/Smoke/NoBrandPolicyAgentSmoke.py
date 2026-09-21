#!/usr/bin/env python3

from __future__ import annotations

import importlib.util
import sys
from pathlib import Path

root = Path(__file__).resolve().parents[2]
agent_path = root / "agents" / "nobrand-policy" / "policy_agent.py"

spec = importlib.util.spec_from_file_location("xboard_nobrand_policy_agent", agent_path)
if spec is None or spec.loader is None:
    raise SystemExit("[FAIL] unable to load policy agent")

agent = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = agent
spec.loader.exec_module(agent)


def assert_true(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(f"[FAIL] {message}")


assert_true(agent.VERSION == "0.1.0", "policy agent version")

for forbidden in [
    ["mieru", "install"],
    ["mieru", "reconfigure"],
    ["mieru", "upgrade"],
    ["mieru", "uninstall"],
    ["mieru", "user-add"],
    ["mieru", "user-del"],
    ["snell", "install"],
]:
    try:
        agent.run_nobrand(forbidden)
    except RuntimeError as exc:
        assert_true(
            "refusing non-policy NoBrand action" in str(exc),
            f"forbidden action rejected before execution: {forbidden}",
        )
    else:
        raise SystemExit(f"[FAIL] forbidden action was accepted: {forbidden}")

state = {
    "alice": {
        "name": "alice",
        "enabled": True,
        "quota_mb": 10240,
        "quota_days": 30,
        "quota_mode": "rolling",
        "expire_at": "2026-10-31",
        "bandwidth_mbps": 50,
        "instance_id": "u1111111111111111",
        "port": 4901,
    }
}
calls: list[list[str]] = []


def fake_run(args: list[str], *, allow_fail: bool = False):
    calls.append(list(args))
    item = state["alice"]

    action = args[1]
    if action == "user-set-quota":
        item["quota_mb"] = int(args[args.index("--quota-mb") + 1])
        item["quota_days"] = int(args[args.index("--quota-days") + 1])
        item["quota_mode"] = args[args.index("--quota-mode") + 1]
    elif action == "user-set-rate":
        item["bandwidth_mbps"] = int(args[args.index("--bandwidth") + 1])
    elif action == "user-set-expire":
        value = args[args.index("--expire") + 1]
        item["expire_at"] = "" if value in {"0", "never"} else value
    elif action == "user-enable":
        item["enabled"] = True
    elif action == "user-disable":
        item["enabled"] = False

    class Result:
        returncode = 0
        stdout = ""
        stderr = ""

    return Result()


agent.run_nobrand = fake_run
agent.export_users = lambda: state

result = agent.reconcile_policy(
    {
        "binding_id": 9,
        "remote_user": "alice",
        "quota_mb": 20480,
        "quota_days": 30,
        "quota_mode": "calendar",
        "bandwidth_mbps": 100,
        "expire": "2027-01-31",
        "enabled": False,
    },
    state,
)

assert_true(result["ok"] is True, "policy reconciliation succeeds")
assert_true(
    [call[1] for call in calls]
    == ["user-set-quota", "user-set-rate", "user-set-expire", "user-disable"],
    "only quota/rate/expiry/enable-state mutations are emitted",
)
assert_true(result["remote_state"]["quota_mb"] == 20480, "quota is synchronized")
assert_true(result["remote_state"]["bandwidth_mbps"] == 100, "bandwidth is synchronized")
assert_true(result["remote_state"]["expire_at"] == "2027-01-31", "expiry is synchronized")
assert_true(result["remote_state"]["enabled"] is False, "enabled state is synchronized")
assert_true("password" not in result["remote_state"], "remote state does not expose password")

missing = agent.reconcile_policy(
    {
        "binding_id": 10,
        "remote_user": "missing",
        "quota_mb": 1024,
        "quota_days": 30,
        "quota_mode": "calendar",
        "bandwidth_mbps": 10,
        "expire": "0",
        "enabled": True,
    },
    state,
)
assert_true(missing["ok"] is False, "missing NoBrand user is reported, not created")

print("[PASS] NoBrand policy agent smoke tests")
