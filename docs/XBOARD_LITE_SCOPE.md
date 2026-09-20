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
