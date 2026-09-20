#!/usr/bin/env python3

from __future__ import annotations

import importlib.util
import sys
from pathlib import Path

root = Path(__file__).resolve().parents[2]
agent_path = root / "agents" / "nobrand" / "nobrand_agent.py"

spec = importlib.util.spec_from_file_location("xboard_nobrand_agent", agent_path)
if spec is None or spec.loader is None:
    raise SystemExit("[FAIL] unable to load NoBrand agent module")

agent = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = agent
spec.loader.exec_module(agent)


def assert_equal(actual, expected, message: str) -> None:
    if actual != expected:
        raise SystemExit(f"[FAIL] {message}: got={actual!r} expected={expected!r}")


assert_equal(
    agent.parse_mita_traffic_metrics(
        '{"traffic":{"UploadBytes":123,"DownloadBytes":456}}'
    ),
    (123, 456),
    "plain metrics JSON",
)

assert_equal(
    agent.parse_mita_traffic_metrics(
        'INFO server metrics\n{"connections":{"CurrEstablished":1},"traffic":{"DownloadBytes":8192,"UploadBytes":4096}}\n'
    ),
    (4096, 8192),
    "metrics JSON with log prefix",
)

for raw, label in [
    ('{"connections":{"CurrEstablished":1}}', "missing traffic group"),
    ('{"traffic":{"UploadBytes":1}}', "missing DownloadBytes"),
    ('{"traffic":{"UploadBytes":-1,"DownloadBytes":2}}', "negative UploadBytes"),
    ('{"traffic":{"UploadBytes":true,"DownloadBytes":2}}', "boolean UploadBytes"),
    ("not-json", "no JSON"),
]:
    try:
        agent.parse_mita_traffic_metrics(raw)
    except RuntimeError:
        pass
    else:
        raise SystemExit(f"[FAIL] parser accepted invalid input: {label}")

print("[PASS] NoBrand agent metrics parser smoke tests")


snell_node = {
    "id": 12,
    "type": "snell",
    "runtime_driver_settings": {"ingress_profile": "legacy-default-route"},
    "users": [
        {
            "user_id": 7,
            "remote_user": "xbn12u7",
            "password": "550e8400-e29b-41d4-a716-446655440000",
        }
    ],
}

snell_users = agent.desired_users(snell_node)
assert_equal(sorted(snell_users), ["xbn12u7"], "Snell reserved namespace accepted")

bad_snell_node = {
    **snell_node,
    "users": [
        {
            "user_id": 7,
            "remote_user": "xbn13u7",
            "password": "550e8400-e29b-41d4-a716-446655440000",
        }
    ],
}
try:
    agent.desired_users(bad_snell_node)
except RuntimeError:
    pass
else:
    raise SystemExit("[FAIL] Snell instance name from another node namespace was accepted")

base_state = {
    "protocol": "snell",
    "instance_id": "s1111111111111111",
    "name": "xbn12u7",
    "version": 5,
    "psk": "550e8400-e29b-41d4-a716-446655440000",
    "listen_port": 4904,
    "advertise_mode": "custom",
    "advertise_host": "entry.example.com",
    "advertise_port": 4904,
    "enabled": True,
    "quic_proxy_enabled": False,
    "ingress_profile_id": "legacy-default-route",
}
wanted = snell_users["xbn12u7"]

assert_equal(
    agent.snell_state_needs_recreate(snell_node, wanted, base_state),
    False,
    "matching Snell state is stable",
)

assert_equal(
    agent.snell_state_needs_recreate(
        snell_node,
        wanted,
        {**base_state, "psk": "different-psk"},
    ),
    True,
    "Snell PSK drift requires isolated instance recreation",
)

assert_equal(
    agent.snell_state_needs_recreate(
        snell_node,
        wanted,
        {**base_state, "quic_proxy_enabled": True},
    ),
    True,
    "Snell QUIC drift is rejected by Phase 4",
)

assert_equal(
    agent.snell_state_needs_recreate(
        snell_node,
        wanted,
        {**base_state, "ingress_profile_id": "other-profile"},
    ),
    True,
    "Snell ingress drift requires isolated instance recreation",
)

assert_equal(
    agent.snell_state_needs_recreate(
        snell_node,
        wanted,
        {**base_state, "version": 4},
    ),
    True,
    "Snell v4 is not accepted by Phase 4",
)

print("[PASS] NoBrand Snell namespace and drift smoke tests")


meter0 = {"version": 1, "instances": {}}
meter1 = agent.advance_snell_meter_state(
    meter0,
    {},
    {"s1111111111111111": 4904},
)
assert_equal(
    meter1["instances"]["s1111111111111111"]["total_u"],
    0,
    "new Snell meter starts from zero total",
)

meter2 = agent.advance_snell_meter_state(
    meter1,
    {
        "s1111111111111111": {
            "upload_bytes": 1000,
            "download_bytes": 2000,
        }
    },
    {"s1111111111111111": 4904},
)
assert_equal(
    (
        meter2["instances"]["s1111111111111111"]["total_u"],
        meter2["instances"]["s1111111111111111"]["total_d"],
    ),
    (1000, 2000),
    "Snell meter accumulates first counter epoch",
)

meter3 = agent.advance_snell_meter_state(
    meter2,
    {
        "s1111111111111111": {
            "upload_bytes": 1500,
            "download_bytes": 2600,
        }
    },
    {"s1111111111111111": 4904},
)
assert_equal(
    (
        meter3["instances"]["s1111111111111111"]["total_u"],
        meter3["instances"]["s1111111111111111"]["total_d"],
    ),
    (1500, 2600),
    "Snell meter accumulates normal counter deltas",
)

meter4 = agent.advance_snell_meter_state(
    meter3,
    {
        "s1111111111111111": {
            "upload_bytes": 120,
            "download_bytes": 220,
        }
    },
    {"s1111111111111111": 4904},
)
assert_equal(
    (
        meter4["instances"]["s1111111111111111"]["total_u"],
        meter4["instances"]["s1111111111111111"]["total_d"],
    ),
    (1620, 2820),
    "Snell meter treats lower raw values as a counter reset",
)

meter5 = agent.advance_snell_meter_state(
    meter4,
    {},
    {"s1111111111111111": 4904},
)
assert_equal(
    (
        meter5["instances"]["s1111111111111111"]["total_u"],
        meter5["instances"]["s1111111111111111"]["total_d"],
    ),
    (1620, 2820),
    "missing raw counter does not invent traffic",
)

reset = agent.reset_snell_meter_raw(
    meter5,
    {"s1111111111111111": 4904},
)
assert_equal(
    (
        reset["instances"]["s1111111111111111"]["raw_u"],
        reset["instances"]["s1111111111111111"]["raw_d"],
        reset["instances"]["s1111111111111111"]["total_u"],
        reset["instances"]["s1111111111111111"]["total_d"],
    ),
    (0, 0, 1620, 2820),
    "Snell meter ruleset rebuild preserves cumulative totals",
)

print("[PASS] Snell meter accumulation smoke tests")


ruleset = agent.render_snell_nft_meter_ruleset({
    "s1111111111111111": 4904,
    "s2222222222222222": 4905,
})
for token in [
    "table inet xboard_nobrand_meter",
    "counter xboard_owner_v1",
    "counter s1111111111111111_up",
    "counter s1111111111111111_down",
    "ct state established tcp dport 4904 counter name s1111111111111111_up",
    "ct state established tcp sport 4904 counter name s1111111111111111_down",
    "ct state established tcp dport 4905 counter name s2222222222222222_up",
    "ct state established tcp sport 4905 counter name s2222222222222222_down",
]:
    if token not in ruleset:
        raise SystemExit(f"[FAIL] Snell nft ruleset missing token: {token}")

print("[PASS] Snell nft ruleset render smoke tests")
