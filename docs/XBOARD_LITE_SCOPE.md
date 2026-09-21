# Xboard Lite

Branch: `xboard-lite-v1`

Xboard Lite removes the public-commerce/referral/support surface from Xboard and keeps the parts needed for private subscription and node management.

## Removed

The runtime implementation has been physically removed for:

- Storefront / self-service plan purchase
- Orders
- Online payments and payment plugins
- Coupons
- Legacy user-generated referral invitations
- Referral commissions
- Tickets
- Notices / announcements
- Public registration without an invite
- Email verification
- Password recovery by email
- Mail-link login
- Mail templates, mail jobs, and scheduled reminder mail

The legacy database migrations are intentionally retained so upgrades from older Xboard databases keep a valid migration history. Those tables are no longer part of the Lite runtime.

## Invite-only access

Registration requires an administrator-issued, one-time access code.

- The administrator generates access codes from the Lite admin panel.
- Registration and invite consumption run in the same database transaction.
- A successful registration marks the code as used.
- A failed registration does not consume the code.
- Used codes cannot be deleted from the admin API.
- Access codes do not create an inviter relationship.
- No commission or referral balance is created.

Admin API:

- `GET /access-invite/fetch`
- `POST /access-invite/generate`
- `POST /access-invite/drop`

## Kept

- User management
- Administrator-assigned plans
- Servers / nodes / routes / machines
- Subscription delivery
- Traffic statistics and reset
- Session/account security
- Telegram account binding, traffic query, and subscription-link query
- Knowledge base
- Feature plugin framework
- Gift Card module (kept for now)
- Existing user frontend (redesign deferred)

## Admin compatibility

The upstream admin frontend is distributed as the compiled `xboard-admin-dist` submodule. Xboard Lite therefore:

- removes retired backend routes and APIs;
- returns zero-value compatibility metrics where the compiled dashboard still expects old commerce fields;
- hides retired menu/actions/cards at runtime;
- adds a Lite invitation-code manager in the admin shell.

A future standalone Lite admin frontend can replace this compatibility layer without changing the backend model.


## Lite admin dashboard

The upstream commerce-oriented dashboard is visually replaced on the admin home page by an operational dashboard.

It shows:

- Server machines: online / total and recent heartbeat
- Protocol nodes: online / total
- Users: total / active / online and online device count
- Traffic: today / current month / cumulative
- Today's node traffic ranking
- Today's user traffic ranking
- Recently registered users
- Users expiring within 7 days

The upstream compiled admin application remains mounted for compatibility. Legacy zero-value commerce statistic endpoints are temporarily retained so the compiled bundle does not fail while its dashboard is hidden. They can be removed after the admin frontend is fully replaced.


## Lite user management

The admin user surface is operational rather than financial.

Kept:

- Account / email / password
- Service plan
- Permission group visibility
- Upload / download / used / remaining traffic
- Speed limit
- Device limit
- Expiration time
- Account status
- Admin / staff flags
- Remarks
- Subscription URL / secret reset
- Online state / online device count
- CSV export without financial data

Removed from the Lite admin API and UI:

- Balance
- Discount
- Referral inviter fields
- Commission type / rate / balance
- Order and finance actions
- Bulk email action

Legacy database columns are retained for migration compatibility, but the Lite user-management API neither exposes nor edits them.

User filtering and sorting use a Lite allow-list so removed financial/referral fields cannot be queried through the admin user API.


## Standalone NoBrand machines

NoBrand-OneClick remains an **independent machine type**. Xboard does not own or deploy its protocols.

The two machine types stay separate:

- `xboard-node`: normal Xboard machine mode; Xboard nodes may be bound to it.
- `nobrand-oneclick`: standalone ike NoBrand machine; normal Xboard protocol nodes cannot be bound to it.

Creating a NoBrand machine produces a checksum-pinned one-click bootstrap for:

- upstream: `ike-sh/NoBrand-OneClick`
- release: `v3.2.2`
- installer SHA-256: `37ba6fb4f35c7e032d05021782a090040af09337c5e95a42cbc0f8f0cf7d66c0`

The bootstrap installs:

1. the upstream NoBrand manager;
2. the narrow Xboard NoBrand Mieru Policy Agent.

It does **not** install Xboard-Node and it does not install, reconfigure, upgrade or remove any NoBrand protocol.

### Mieru policy bridge

Xboard may explicitly map an Xboard account to an **already-existing** NoBrand Mieru username on a NoBrand machine.

The policy bridge synchronizes only:

- traffic quota: Xboard user `transfer_enable` -> NoBrand `quota_mb`;
- quota mode/window: mapping setting -> NoBrand `quota_mode/quota_days`;
- speed limit: Xboard user `speed_limit` -> NoBrand `bandwidth_mbps`;
- expiry: Xboard user `expired_at` -> NoBrand `expire_at`;
- account eligibility: banned / no plan / expired -> NoBrand user disabled.

The agent allow-list contains only:

```text
nobrand mieru user-export
nobrand mieru user-set-quota
nobrand mieru user-set-rate
nobrand mieru user-set-expire
nobrand mieru user-enable
nobrand mieru user-disable
```

It cannot run:

```text
install
reconfigure
upgrade
uninstall
user-add
user-del
arbitrary shell
```

If a mapped NoBrand username does not exist, the mapping reports an error and the agent does not create it.

The initial policy bridge supports **Mieru only**, because ike v3.2.2 exposes native per-user quota + bandwidth + expiry controls for Mieru. Snell/Hysteria2/TUIC/VLESS do not currently expose the same complete three-part policy contract.

The bridge currently controls NoBrand enforcement but does not import NoBrand usage into Xboard's `u/d` counters. NoBrand remains the enforcement source for the mapped Mieru quota.

The Lite backend still rejects binding a normal Xboard protocol node to a `nobrand-oneclick` machine.

