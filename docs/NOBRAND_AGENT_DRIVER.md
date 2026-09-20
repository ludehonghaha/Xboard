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

Do not change the pinned NoBrand version without reviewing the upstream CLI contract and updating tests.

## Why Hybrid instead of replacing Xboard-Node

Current Xboard-Node already handles several protocols natively. NoBrand is only selected where its management model is useful.

A machine can be:

- `xboard-node`: native Xboard-Node only
- `nobrand-hybrid`: Xboard-Node plus NoBrand manager and companion

A node can be:

- `runtime_driver=native`: owned by Xboard-Node
- `runtime_driver=nobrand`: owned by the NoBrand Companion

Native machine discovery intentionally excludes NoBrand-owned nodes so both runtimes cannot bind the same listener.

## Phase 2 status

Implemented:

- Machine `agent_driver`: `xboard-node | nobrand-hybrid`
- Machine `agent_settings`
- Node `runtime_driver`: `native | nobrand`
- Node `runtime_driver_settings`
- Exact NoBrand v3.2.2 manager bootstrap with checksum verification
- Standalone Xboard NoBrand Companion `0.2.0`
- Companion systemd installer
- Machine-auth desired-state endpoint:
  - native: `POST /api/v2/server/machine/nodes`
  - NoBrand: `POST /api/v2/server/machine/nobrand-nodes`
- Machine-auth binding report endpoint:
  - `POST /api/v2/server/machine/nobrand-bindings`
- Per-user NoBrand endpoint binding table
- Subscription endpoint resolution for NoBrand Mieru dedicated users
- Clash/Mihomo Mieru rendering with distinct username/password
- General `mierus://` rendering
- Mieru user add/delete reconciliation
- Mieru expiry reconciliation
- Mieru per-instance bandwidth reconciliation
- Mieru Display Endpoint host reconciliation
- NoBrand `user-export` JSON parsing and per-user endpoint reporting
- CI syntax checks for PHP, Python and Bash

## Phase 2 runtime boundary

The pinned upstream NoBrand release supports more products, but the live companion currently reconciles only:

- `mieru`

Therefore the panel rejects `runtime_driver=nobrand` for other Xboard node types in Phase 2.

Also, one machine may currently own only one NoBrand Mieru node. This matches NoBrand's current machine-level Mieru state model and avoids two Xboard logical nodes competing for the same authoritative NoBrand Mieru state.

Future phases can add dedicated models/drivers for:

- Snell v4/v5
- Hysteria2
- TUIC v5
- VLESS FinalMask/Sudoku
- VLESS REALITY
- SSH Tunnel
- Port Forward

Do not infer "supported by upstream NoBrand" as "implemented by the Xboard companion."

## Mieru user model

NoBrand Mieru uses isolated per-user instances with independent ports, while native Xboard nodes normally expose one node port to all users.

Phase 2 therefore stores a per-user binding:

```text
Xboard user
  -> remote_user = xb<user_id>
  -> password = existing Xboard UUID
  -> NoBrand isolated instance
  -> actual/display port reported by companion
  -> v2_nobrand_user_binding
  -> subscription renderer
```

The binding table stores endpoint/runtime metadata only. It does not duplicate the password or a full share URI.

If a NoBrand Mieru binding has not been reported yet, that node is omitted from the user's subscription instead of falling back to an incorrect generic node port.

## Reconciliation ownership

The companion owns only users in the reserved namespace:

```text
xb<positive integer>
```

Examples:

```text
xb18
xb1024
```

Manual NoBrand users outside that namespace are not deleted or modified.

Desired user state currently synchronizes:

- Xboard user ID
- reserved NoBrand username
- Xboard UUID as the NoBrand password
- expiry date
- speed limit as NoBrand per-instance bandwidth
- display host

Traffic quota is **not** automatically mirrored into NoBrand in Phase 2. Xboard traffic is global/account-level while NoBrand quota is per local Mieru instance; blindly copying the same total quota to several nodes would multiply the user's usable traffic.

NoBrand traffic reporting back into Xboard is a later phase.

## Security model

The companion does not provide remote shell execution.

It pulls structured desired state and builds local subprocess argument arrays. Python `subprocess.run(...)` is used without `shell=True`.

The panel never sends commands such as:

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

Routine logs do not print passwords, UUIDs, exported share links or full NoBrand command arguments.

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

## Current limitations

- systemd target hosts only for the companion installer
- one NoBrand Mieru logical node per machine
- TCP or UDP Mieru in the current Xboard node model; BOTH is not exposed yet
- no automatic global Mieru protocol reconfigure after deployment; protocol drift fails closed
- no NoBrand traffic accounting pushed into Xboard yet
- no dedicated Admin UI for advanced NoBrand runtime settings yet
- companion installer currently follows the `xboard-lite-v1` branch and should be release-pinned before production rollout

## Next phase

The next useful work is:

1. Admin UI for choosing `NoBrand Hybrid` and Mieru runtime settings.
2. Runtime status/error reporting in the machine page.
3. NoBrand traffic/accounting ingestion.
4. Snell as the next dedicated NoBrand protocol model.
5. Release-tag/checksum pinning for the Xboard-owned companion itself.
