# Xboard Lite scope

Branch: `xboard-lite-v1`

## Removed from the product surface

- Storefront / self-service plan purchase
- Orders
- Online payments
- Coupons
- Legacy referral invitations
- Referral commissions
- Tickets
- Notices
- Open/public registration
- Password recovery by email
- Email verification and mail-link login

## Reworked

### Invitation access

Registration remains available only with a valid one-time access invite.

- Access invites are issued by administrators.
- An invite is consumed atomically on successful registration.
- The registered user is not linked to an inviter.
- No commission or referral relationship is created.

Admin API:

- `GET /access-invite/fetch`
- `POST /access-invite/generate`
- `POST /access-invite/drop`

## Kept

- User management
- Plans as administrator-assigned service profiles
- Servers / nodes / routes / machines
- Subscription delivery
- Traffic statistics
- Sessions / account security
- Telegram integration
- Knowledge base
- Plugin framework
- Traffic reset
- User frontend: kept unchanged for now; redesign will be handled separately.

## Note

The legacy implementation files and database tables for removed commerce/referral/support features are not dropped in this first safe cut. Their public/admin routes and scheduled jobs are disabled, so the features are functionally removed without risking migration breakage. A later cleanup can physically purge dead classes and schema after boot/upgrade compatibility is verified.
