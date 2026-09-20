#!/usr/bin/env python3

from __future__ import annotations

import importlib.util
from pathlib import Path

root = Path(__file__).resolve().parents[2]
agent_path = root / "agents" / "nobrand" / "nobrand_agent.py"

spec = importlib.util.spec_from_file_location("xboard_nobrand_agent", agent_path)
if spec is None or spec.loader is None:
    raise SystemExit("[FAIL] unable to load NoBrand agent module")

agent = importlib.util.module_from_spec(spec)
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
