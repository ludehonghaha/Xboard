# NoBrand Hybrid Agent Driver

## Goal

Xboard Lite integrates `ike-sh/NoBrand-OneClick` as an **external runtime provider**, not as vendored source code.

Architecture:

```text
Xboard Lite
  └─ ServerMachine
      ├─ Xboard-Node machine runtime      -> runtime_driver=native
      └─ Xboard NoBrand Companion         -> runtime_driver=nobrand
             └─ local nobrand CLI
                  └─ ike-sh/NoBrand-OneClick runtime/state
```

The companion is stored in this Xboard fork under `agents/nobrand/`. The upstream NoBrand source is not copied into this repository.

## Source and license boundary

Upstream:

- Repository: `ike-sh/NoBrand-OneClick`
- License: GPL-3.0
- Pinned release: `v3.2.2`
- Installer SHA-256: `37ba6fb4f35c7e032d05021782a090040af09337c5e95a42cbc0f8f0cf7d66c0`

Xboard Lite does **not** copy `install-nobrand.sh` or the upstream `src/` tree into this repository.

Hybrid bootstrap:

1. Installs Xboard-Node machine mode.
2. Downloads the exact NoBrand v3.2.2 release installer.
3. Verifies the pinned SHA-256.
4. Runs NoBrand manager-only installation.
5. Installs the Xboard-owned NoBrand Companion systemd service.

Do not change the pinned NoBrand version without reviewing the upstream CLI/state contracts and updating tests.

## Why Hybrid instead of replacing Xboard-Node

Xboard-Node remains the native runtime. NoBrand is selected only for nodes that need the NoBrand management model.

A machine can be:

- `xboard-node`: native Xboard-Node only
- `nobrand-hybrid`: Xboard-Node plus NoBrand manager and companion

A node can be:

- `runtime_driver=native`: owned by Xboard-Node
- `runtime_driver=nobrand`: owned by the NoBrand Companion

Native machine discovery intentionally excludes NoBrand-owned nodes so both runtimes cannot own the same listener.

## Phase 4 status

Current companion version: `0.4.0`.

Implemented panel/runtime protocols:

- NoBrand Mieru
- NoBrand Snell v5, QUIC Proxy off

Implemented infrastructure:

- Machine `agent_driver`: `xboard-node | nobrand-hybrid`
- Machine `agent_settings`
- Node `runtime_driver`: `native | nobrand`
- Node `runtime_driver_settings`
- Exact NoBrand v3.2.2 manager bootstrap with checksum verification
- Standalone Xboard NoBrand Companion `0.4.0`
- Companion systemd installer
- Machine-auth desired-state endpoint:
  - native: `POST /api/v2/server/machine/nodes`
  - NoBrand: `POST /api/v2/server/machine/nobrand-nodes`
- Machine-auth binding endpoint:
  - `POST /api/v2/server/machine/nobrand-bindings`
- Mieru absolute-traffic endpoint:
  - `POST /api/v2/server/machine/nobrand-traffic`
- Companion heartbeat/status endpoint:
  - `POST /api/v2/server/machine/nobrand-status`
- Per-user NoBrand endpoint binding table
- Durable NoBrand traffic-report outbox
- Admin NoBrand manager overlay
- Companion health display: online/offline, OK/Error, managed users, bindings, traffic readings and reconciliation latency
- CI checks for PHP, Python, Bash, Lite Admin JavaScript, traffic watermarks, Mita metrics parsing and Snell ownership/drift rules

## Runtime boundary

The pinned NoBrand release exposes more products than Phase 4 implements.

The companion currently owns:

- `mieru`
- `snell` v5 with QUIC Proxy disabled

Not yet implemented as NoBrand Xboard runtimes:

- Hysteria2
- TUIC v5
- VLESS FinalMask/Sudoku
- VLESS REALITY
- SSH Tunnel
- Port Forward

Do not infer "supported upstream" as "implemented by the Xboard companion."

### Mieru machine boundary

NoBrand Mieru uses one authoritative machine-level user state. Phase 4 therefore permits only one NoBrand Mieru logical node per machine.

### Snell machine boundary

NoBrand Snell is multi-instance. A machine may therefore contain multiple Xboard Snell logical nodes. Each Xboard user receives an isolated Snell instance under a node-specific reserved name.

## Mieru user model

NoBrand Mieru uses isolated per-user Mita instances with independent ports.

```text
Xboard user
  -> remote_user = xb<user_id>
  -> password = existing Xboard UUID
  -> NoBrand isolated Mita instance
  -> real display endpoint reported by companion
  -> v2_nobrand_user_binding
  -> subscription renderer
```

The binding table stores endpoint/runtime metadata, not another copy of the password or a full share URI.

If a binding has not been reported yet, the node is omitted from that user's subscription instead of exposing the logical node's placeholder port.

Desired Mieru state synchronizes:

- Xboard user identity
- UUID credential
- expiry
- Xboard speed limit -> NoBrand Mieru per-instance bandwidth
- Display Host
- optional Ingress Profile

## Mieru traffic accounting

Phase 4 feeds NoBrand Mieru traffic into the normal Xboard accounting pipeline.

The companion reads each isolated Mita instance over its local management UDS and accepts only the official cumulative counters:

- `traffic.UploadBytes` -> Xboard upload `u`
- `traffic.DownloadBytes` -> Xboard download `d`

The companion reports **absolute cumulative counters**, never calculated deltas.

The panel stores a per-binding traffic watermark and calculates deltas transactionally:

```text
absolute Mita counters
  -> NoBrand traffic report outbox
  -> binding watermark
  -> incremental u/d
  -> TrafficFetchJob
  -> StatUserJob
  -> StatServerJob
```

Accounting rules:

- first observation of an instance is a baseline and does not back-bill traffic that occurred before Xboard started observing it;
- repeated absolute reports produce zero duplicate traffic;
- out-of-order lower counters cannot move the watermark backwards;
- a new isolated `instance_id` establishes a new baseline;
- user/server/stat updates and watermark advancement happen in the same database transaction.

NoBrand quota is **not** copied into every Mieru instance. Xboard remains the account-level traffic authority. Copying the same global allowance into several local instances would multiply usable quota.

## Snell v5 user model

Snell has one PSK per server instance rather than a native multi-user identity model. Phase 4 therefore creates one isolated NoBrand Snell v5 instance for every Xboard user/node pair.

Reserved instance name:

```text
xbn<node_id>u<user_id>
```

Example:

```text
xbn12u7
```

Model:

```text
Xboard Snell logical node #12
  + Xboard user #7
  -> NoBrand instance name xbn12u7
  -> PSK = existing Xboard UUID
  -> independently allocated NoBrand TCP port
  -> per-user binding
  -> Mihomo / Surge / sing-box renderer
```

The companion may create, recreate, update the Display Endpoint for, or delete only names in this reserved namespace.

A Snell instance is recreated when an authoritative field drifts, including:

- PSK
- Snell major version
- QUIC Proxy state
- explicitly managed Ingress Profile

Manual NoBrand Snell instances with other names remain outside Xboard ownership.

### Snell client output

Verified Phase 4 output:

- Mihomo / Clash Meta: `type: snell`, v5, per-user PSK/port
- Surge: standard Snell policy line with `version = 5`
- sing-box: NoBrand-compatible v5 non-QUIC wire representation using outbound `version: 4`

Phase 4 does **not** invent a generic Snell URI. Clients whose Xboard renderer has no verified Snell representation simply do not receive the Snell node.

### Snell limitations

Phase 4 deliberately keeps NoBrand Snell v5 QUIC Proxy disabled.

Mihomo's ordinary `udp: true` Snell relay capability is not the same thing as the NoBrand server's v5 QUIC Proxy listener.

Snell traffic is **not yet fed into Xboard accounting**. NoBrand Snell currently exposes isolated server instances but not the same Mita cumulative traffic counters used by Mieru.

Xboard user expiry/banning/traffic eligibility still controls whether a Snell instance appears in desired state, so an ineligible user is removed from the managed Snell set on reconciliation. However, until Snell accounting is added, traffic generated through Snell does not decrement the user's Xboard traffic allowance.

Xboard `speed_limit` is also not yet translated into a NoBrand Snell per-instance rate control.

## Reconciliation ownership

Reserved Xboard namespaces:

```text
Mieru: xb<user_id>
Snell: xbn<node_id>u<user_id>
```

The companion never treats arbitrary NoBrand object names as Xboard-owned resources.

This is the deletion boundary: orphan cleanup applies only to the reserved namespaces above.

## Security model

The companion does not expose arbitrary remote shell execution.

It pulls structured desired state and builds fixed local subprocess argument arrays. Python `subprocess.run(...)` is used without `shell=True`.

The panel never sends command strings such as:

```text
shell.exec
bash -c ...
arbitrary_command
```

The machine token is stored root-only in:

```text
/etc/xboard-nobrand-agent.json
```

The systemd service runs as root because NoBrand lifecycle actions require root privileges.

Routine logs do not print passwords, UUIDs, PSKs, exported share links or complete credential-bearing command lines.

Panel-owned traffic watermarks cannot be overwritten through the companion binding metadata endpoint.

## Local files

```text
/usr/local/bin/xboard-nobrand-agent
/etc/xboard-nobrand-agent.json
/etc/systemd/system/xboard-nobrand-agent.service
```

Repository source:

```text
agents/nobrand/nobrand_agent.py
agents/nobrand/install.sh
```

Relevant NoBrand managed state:

```text
Mieru:
/etc/mita/instances/<instance_id>/server.json
/run/mita-instances/<instance_id>.sock

Snell:
/var/lib/nobrand-oneclick/snell/instances/<instance_id>.json
```

## Admin controls

The Lite Admin compatibility overlay provides:

- machine mode: Xboard-Node / NoBrand Hybrid
- Hybrid one-line install command
- Mieru Runtime assignment and settings
- Snell v5 logical-node creation
- Snell v5 logical-node deletion
- NoBrand companion health/status

Snell creation asks for the logical node name, Display Host, Hybrid machine, permission group and optional Ingress Profile. Real per-user Snell ports are always supplied by companion bindings rather than the logical node placeholder.

## Current limitations

- companion installer targets systemd hosts;
- one NoBrand Mieru logical node per machine;
- Mieru Xboard model currently exposes TCP or UDP, not BOTH;
- changing global Mieru protocol mode after deployment fails closed rather than automatically restarting all instances;
- Snell is v5 only and QUIC Proxy is disabled;
- Snell traffic accounting and Xboard speed-limit enforcement are not implemented yet;
- Hysteria2/TUIC/VLESS/SSH/Forward NoBrand runtimes are not implemented yet;
- the compatibility Admin overlay should eventually be replaced by a source-built Lite frontend;
- the companion installer currently follows the `xboard-lite-v1` branch and should be release/tag/checksum pinned before production rollout.

## Next phase

The highest-value next steps are:

1. Find a reliable per-instance Snell byte-accounting source and integrate it without double counting.
2. Add NoBrand Hysteria2/TUIC only after their ownership and accounting models are defined.
3. Release-tag and checksum-pin the Xboard-owned companion itself.
4. Replace the compiled-admin compatibility overlay with a native Lite Admin source build.
