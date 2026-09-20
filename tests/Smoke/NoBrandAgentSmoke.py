#!/usr/bin/env python3

from __future__ import annotations

import importlib.util
import json
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


hy2_base = {
    "log": {"loglevel": "warning"},
    "inbounds": [{
        "tag": "nobrand-hy2",
        "listen": "0.0.0.0",
        "port": 23037,
        "protocol": "hysteria",
        "settings": {
            "version": 2,
            "clients": [{"auth": "bootstrap", "email": "hysteria2@xray"}],
        },
        "streamSettings": {
            "network": "hysteria",
            "security": "tls",
            "tlsSettings": {
                "alpn": ["h3"],
                "certificates": [{
                    "certificateFile": "/etc/nobrand-oneclick/hysteria2/hysteria2-cert.pem",
                    "keyFile": "/etc/nobrand-oneclick/hysteria2/hysteria2-key.pem",
                }],
            },
            "hysteriaSettings": {"version": 2},
            "finalmask": {
                "udp": [{
                    "type": "salamander",
                    "settings": {"password": "obfs-secret"},
                }]
            },
        },
    }],
    "outbounds": [{"tag": "direct", "protocol": "freedom"}],
    "routing": {"rules": []},
}

hy2_after = agent.build_hy2_multiclient_config(
    hy2_base,
    [
        {
            "user_id": 7,
            "password": "550e8400-e29b-41d4-a716-446655440000",
        },
        {
            "user_id": 8,
            "password": "660e8400-e29b-41d4-a716-446655440000",
        },
    ],
)
agent.assert_hy2_overlay_preserves_runtime(hy2_base, hy2_after)

assert_equal(
    hy2_after["inbounds"][0]["settings"]["clients"],
    [
        {
            "auth": "550e8400-e29b-41d4-a716-446655440000",
            "email": "xbh7@xboard.invalid",
        },
        {
            "auth": "660e8400-e29b-41d4-a716-446655440000",
            "email": "xbh8@xboard.invalid",
        },
    ],
    "HY2 overlay replaces only the client auth list",
)

assert_equal(
    hy2_after["inbounds"][0]["streamSettings"]["finalmask"]["udp"][0]["settings"]["password"],
    "obfs-secret",
    "HY2 overlay preserves Salamander password",
)
assert_equal(
    hy2_after["inbounds"][0]["port"],
    23037,
    "HY2 overlay preserves listener port",
)
assert_equal(
    hy2_base["inbounds"][0]["settings"]["clients"][0]["auth"],
    "bootstrap",
    "HY2 overlay does not mutate the base config object",
)

bad_hy2 = json.loads(json.dumps(hy2_base))
bad_hy2["inbounds"][0]["protocol"] = "vless"
try:
    agent.build_hy2_multiclient_config(
        bad_hy2,
        [{"user_id": 7, "password": "auth"}],
    )
except RuntimeError:
    pass
else:
    raise SystemExit("[FAIL] HY2 overlay accepted a non-Hysteria inbound")

print("[PASS] HY2 multi-client overlay smoke tests")


hy2_node = {
    "id": 21,
    "type": "hysteria",
    "host": "211.136.162.188",
    "server_port": 23037,
    "runtime_driver_settings": {
        "advertise_host": "211.136.162.188",
        "ingress_profile": "legacy-default-route",
    },
    "protocol_settings": {
        "version": 2,
        "tls": {
            "server_name": "www.nvidia.com",
            "allow_insecure": True,
        },
        "obfs": {
            "open": True,
            "type": "salamander",
            "password": None,
        },
    },
    "users": [{
        "user_id": 7,
        "remote_user": "xbh7",
        "password": "550e8400-e29b-41d4-a716-446655440000",
    }],
}

hy2_users = agent.desired_users(hy2_node)
assert_equal(sorted(hy2_users), ["xbh7"], "HY2 reserved client namespace accepted")
assert_equal(
    agent.hy2_node_parameters(hy2_node),
    {
        "sni": "www.nvidia.com",
        "port": 23037,
        "display_host": "211.136.162.188",
        "ingress_profile": "legacy-default-route",
    },
    "HY2 node parameters normalized",
)

hy2_state = {
    "protocol": "hysteria2",
    "enabled": True,
    "listen_port": 23037,
    "sni": "www.nvidia.com",
    "advertise_host": "211.136.162.188",
    "advertise_port": 23037,
    "ingress_profile_id": "legacy-default-route",
}
assert_equal(
    agent.hy2_state_matches_node(hy2_node, hy2_state),
    True,
    "matching HY2 runtime state is stable",
)
assert_equal(
    agent.hy2_state_matches_node(
        hy2_node,
        {**hy2_state, "listen_port": 23038},
    ),
    False,
    "HY2 listener drift requires NoBrand reconfigure",
)
assert_equal(
    agent.hy2_state_matches_node(
        hy2_node,
        {**hy2_state, "sni": "www.microsoft.com"},
    ),
    False,
    "HY2 SNI drift requires NoBrand reconfigure",
)
assert_equal(
    agent.hy2_state_matches_node(
        hy2_node,
        {**hy2_state, "advertise_host": "203.0.113.7"},
    ),
    False,
    "HY2 display endpoint drift requires NoBrand reconfigure",
)

bad_hy2_user_node = json.loads(json.dumps(hy2_node))
bad_hy2_user_node["users"][0]["remote_user"] = "xbh8"
try:
    agent.desired_users(bad_hy2_user_node)
except RuntimeError:
    pass
else:
    raise SystemExit("[FAIL] HY2 client name did not match user_id")

print("[PASS] HY2 desired-state and drift smoke tests")


hy2_owner = {
    "version": 1,
    "node_id": 21,
    "managed_by": "xboard-nobrand-agent",
    "node_spec": agent.hy2_node_parameters(hy2_node),
}
assert_equal(
    agent.hy2_owner_matches_node(hy2_owner, hy2_node),
    True,
    "HY2 owner marker matches the requested panel spec",
)

hy2_node_changed_ingress = json.loads(json.dumps(hy2_node))
hy2_node_changed_ingress["runtime_driver_settings"]["ingress_profile"] = "other-profile"
assert_equal(
    agent.hy2_owner_matches_node(hy2_owner, hy2_node_changed_ingress),
    False,
    "HY2 requested ingress change is detected through owner spec",
)

hy2_state_resolved_ingress = {
    **hy2_state,
    "ingress_profile_id": "resolved-profile-id",
}
assert_equal(
    agent.hy2_state_matches_node(hy2_node, hy2_state_resolved_ingress),
    True,
    "HY2 runtime-state check does not loop on ingress name-to-id resolution",
)

print("[PASS] HY2 owner-spec smoke tests")
