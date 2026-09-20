# NoBrand Hybrid Agent Driver

## Goal

Xboard Lite integrates `ike-sh/NoBrand-OneClick` as an **external runtime provider**, not as vendored source code.

Architecture:

```text
Xboard Lite
  └─ ServerMachine
      ├─ Xboard-Node machine runtime      -> runtime_driver=native
      └─ NoBrand companion (future)       -> runtime_driver=nobrand
             └─ local nobrand CLI
                  └─ ike-sh/NoBrand-OneClick runtime/state
```

This keeps Xboard's native runtime available and lets an administrator opt into NoBrand only where its deployment model is useful.

## Source and license boundary

Upstream:

- Repository: `ike-sh/NoBrand-OneClick`
- License: GPL-3.0
- Pinned release for Phase 1: `v3.2.2`
- Installer SHA-256: `37ba6fb4f35c7e032d05021782a090040af09337c5e95a42cbc0f8f0cf7d66c0`

Xboard Lite does **not** copy `install-nobrand.sh` or the upstream `src/` tree into this repository.

The machine bootstrap downloads the exact upstream release asset and verifies its SHA-256 before running manager-only installation. This repository stores only the adapter contract, release identity, checksum, declarative state and Xboard-specific integration code.

Do not change the pinned version without reviewing the new upstream CLI contract and updating tests.

## Why Hybrid instead of replacing Xboard-Node

Current Xboard-Node already handles several protocols natively, including Mieru, Hysteria2, TUIC and VLESS/REALITY through its supported kernels.

NoBrand remains useful for behavior that is specific to the NoBrand management model, such as:

- NoBrand Mieru multi-user / dedicated-instance management
- Mieru quota, expiration and per-instance rate controls
- Multi-Ingress / Display Endpoint policy
- Snell
- VLESS FinalMask/Sudoku deployment
- SSH Tunnel
- Port Forward
- NoBrand-specific lifecycle / backup / Doctor behavior

A machine can therefore be:

- `xboard-node`: native Xboard-Node only
- `nobrand-hybrid`: Xboard-Node plus NoBrand manager / companion

A node can be:

- `runtime_driver=native`: owned by Xboard-Node
- `runtime_driver=nobrand`: reserved for the NoBrand companion

Native machine discovery intentionally excludes NoBrand nodes so both runtimes cannot own the same listener.

## Phase 1 status

Implemented in the panel:

- Machine `agent_driver`: `xboard-node | nobrand-hybrid`
- Machine `agent_settings`
- Node `runtime_driver`: `native | nobrand`
- Node `runtime_driver_settings`
- Validation requiring NoBrand nodes to bind to a NoBrand Hybrid machine
- Exact v3.2.2 manager bootstrap with checksum verification
- Driver capability endpoint for Admin
- Separate machine-auth desired-state endpoint:
  - native: `POST /api/v2/server/machine/nodes`
  - NoBrand: `POST /api/v2/server/machine/nobrand-nodes`
- Native Xboard-Node discovery excludes `runtime_driver=nobrand`

Not implemented yet:

- The Xboard-Node NoBrand companion process that reconciles desired state into local `nobrand` CLI actions
- NoBrand runtime status / result reporting back to the panel
- NoBrand user reconciliation
- Dedicated Xboard node models/renderers for Snell and other upstream-only products
- Admin UI for selecting/configuring the driver beyond the backend contract

Until the companion exists, a NoBrand node is declarative state only and must not be represented as automatically deployed.

## Security model

The companion must **not** expose arbitrary remote shell execution.

Panel-to-agent control must be structured and allow-listed. Examples:

```text
manager.install
manager.status
manager.doctor
mieru.install
mieru.user-add
mieru.user-set-quota
snell.install
hy2.install
...
```

The companion maps an allowed action and validated parameters to a fixed local `nobrand` CLI invocation.

The panel must never send strings such as:

```text
shell.exec
bash -c ...
arbitrary_command
```

Secrets returned by explicit `show` / `export` operations must not be written to normal application logs.

## Protocol boundary

The pinned NoBrand release exposes a broader upstream scope than Xboard currently models.

Phase 1 Xboard node types that can be marked for the NoBrand runtime:

- `mieru`
- `hysteria` (Hysteria2 in current Xboard model)
- `tuic`
- `vless`

Upstream NoBrand capabilities not yet modeled as dedicated Xboard node types include Snell, SSH Tunnel and Forward. VLESS Sudoku also needs a clear panel model before it is exposed as a normal node.

Do not infer "present upstream" as "implemented in the panel."

## Next companion contract

The future Xboard-Node fork should add a NoBrand companion that:

1. Runs only when the machine is configured as `nobrand-hybrid`.
2. Authenticates with the existing machine ID/token.
3. Polls or subscribes to NoBrand desired state.
4. Compares desired state with local NoBrand state.
5. Executes only allow-listed local actions.
6. Reports sanitized status and reconciliation results.
7. Never sends credentials/private keys in routine heartbeat logs.
8. Leaves `runtime_driver=native` nodes entirely to the existing Xboard-Node machine orchestrator.

The companion code belongs in the user's Xboard-Node fork. The ike upstream script still does not need to be copied into that fork.
