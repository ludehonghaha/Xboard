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


## NoBrand Hybrid Agent

Xboard Lite has a Phase 5 external runtime integration for `ike-sh/NoBrand-OneClick`.

- Upstream GPL source is not vendored into Xboard Lite.
- The panel pins NoBrand v3.2.2 and verifies the release installer SHA-256.
- Machines distinguish `xboard-node` from `nobrand-hybrid`.
- Nodes distinguish `native` from `nobrand` runtime ownership.
- Native machine discovery excludes NoBrand-owned nodes to avoid double ownership.
- The Xboard-owned companion is stored under `agents/nobrand/` and invokes only structured local `nobrand` actions without `shell=True`.
- Companion version `0.5.0` currently manages Mieru, Snell v5, and Hysteria2 multi-auth.
- Mieru uses per-user isolated Mita instances and real per-user endpoint bindings.
- Mieru absolute Mita byte counters are converted to idempotent deltas and fed into the normal Xboard user/server traffic pipeline.
- Snell v5 uses one isolated NoBrand instance per Xboard user/node pair, with the Xboard UUID reused as the PSK.
- Snell v5 QUIC Proxy is intentionally disabled in Phase 5.
- Snell nodes render to verified Mihomo, Surge and sing-box formats; no unverified generic Snell URI is invented.
- Snell traffic accounting and per-user speed-limit enforcement are not implemented yet.
- Hysteria2 uses one Xray UDP listener with one UUID Auth per eligible Xboard user; its Companion overlay changes only `settings.clients[]`, validates the candidate with Xray, and rolls back on restart failure.
- Hysteria2 traffic accounting and per-user speed-limit enforcement are not implemented yet.
- A dedicated NoBrand Admin overlay manages Hybrid machines, Mieru Runtime settings, Snell logical nodes, Hysteria2 multi-auth nodes and Companion health.

Reserved Xboard-owned NoBrand names are:

```text
Mieru: xb<user_id>
Snell: xbn<node_id>u<user_id>
Hysteria2 metadata: xbh<user_id>
```

Manual NoBrand resources outside these namespaces are not removed by the companion.

See [NOBRAND_AGENT_DRIVER.md](NOBRAND_AGENT_DRIVER.md).

