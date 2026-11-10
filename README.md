# MulaEarn — Combined Frontend + Backend

This is one project, not two glued together: the frontend pages
(`index.html`, `login.html`, `register.html`, `dashboard.html`,
`affiliate.html`, `games.html`, and `assets/`) live directly inside
`public/`, alongside the PHP API (`public/api/`) and the admin panel
(`public/admin/`). They're served from the same origin on purpose —
same-origin means session cookies just work with zero CORS
configuration to get wrong.

```
mulaearn-backend/
├── public/              ← run a web server pointed at THIS folder
│   ├── index.html       ← frontend pages (served as static files)
│   ├── login.html
│   ├── register.html
│   ├── dashboard.html
│   ├── affiliate.html
│   ├── games.html
│   ├── assets/          ← shared CSS/JS, including api.js + app.js
│   ├── api/             ← the JSON backend the frontend calls
│   ├── admin/           ← the separate admin panel
│   ├── r.php            ← affiliate click-tracking redirect
│   └── bootstrap.php
├── src/                 ← PHP service classes (Auth, WalletService, etc.)
├── config/
├── database/            ← schema.sql (MySQL) + schema.sqlite.sql (dev)
├── bin/create_admin.php
├── tests/
└── README.md            ← you are here
```

**To run it**: point PHP's built-in server (or a real web server) at
`public/` as the document root — see "Local setup" below. Opening the
HTML files directly (`file://`) will NOT work; they depend on the API
being served from the same origin.

---

# MulaEarn Backend — Slice 1: Auth, Sessions, Wallets

This is the first real backend slice from `BACKEND_PLAN.md`: users,
authentication, sessions, and a wallet system where balances can
**only** be changed server-side through `WalletService`. It's been
built and tested end-to-end (see "What's been tested" below) — this
isn't a sketch, it runs.

## What's in scope (and what isn't)

In scope: registration (with server-side referral capture from
`?ref=`), login/logout, session security (regeneration, lockout after
repeated failures), CSRF protection, and a wallet service where every
balance change is a `transactions` row written inside a DB
transaction.

**Not in scope, on purpose:** no activation/joining fee, no
multi-level (L1/L2/L3) recruitment commissions. The `referral_bonus`
transaction type exists in the schema for a *legitimate* referral
reward — pay it out when a referred user completes a real, verified
action (their first approved affiliate conversion, say), not on
signup or payment. Nothing currently triggers it automatically; that
wiring is a deliberate next step, not an oversight.

## Requirements

- PHP 8.1+ with `pdo_sqlite` (local dev) or `pdo_mysql` (production)
- SQLite3 CLI (local dev only, to build the dev database)
- MySQL 8 / MariaDB 10.6+ for production

## Local setup (SQLite, zero external dependencies)

```bash
# 1. Build the local dev database
sqlite3 storage/mulaearn.sqlite < database/schema.sqlite.sql

# 2. Run the built-in PHP server
DB_DRIVER=sqlite DB_DATABASE=$(pwd)/storage/mulaearn.sqlite php -S 127.0.0.1:8080 -t public

# 3. Try it
curl http://127.0.0.1:8080/api/me.php
```

## Production setup (MySQL/MariaDB)

```bash
mysql -u root -p -e "CREATE DATABASE mulaearn CHARACTER SET utf8mb4"
mysql -u root -p mulaearn < database/schema.sql

# Set real environment variables (never commit these):
export APP_ENV=production
export APP_DEBUG=false
export DB_DRIVER=mysql
export DB_HOST=127.0.0.1
export DB_DATABASE=mulaearn
export DB_USERNAME=mulaearn_app
export DB_PASSWORD='use-a-real-secret-here'
```

Point your web server (nginx/Apache + PHP-FPM) at `public/` as the
document root. Never expose `src/`, `config/`, or `database/` directly.

## API endpoints in this slice

| Endpoint | Method | Auth required | Purpose |
|---|---|---|---|
| `/api/me.php` | GET | No | Current user (or null) + issues a CSRF token |
| `/api/register.php` | POST | No | Create account, auto-login, capture referral |
| `/api/login.php` | POST | No | Login by email or username |
| `/api/logout.php` | POST | Yes | Destroy session |
| `/api/wallet.php` | GET | Yes | Balance + recent transactions for the **current session user only** |

Every POST endpoint requires a `csrf_token` from a prior `/api/me.php`
call, in the request body. `/api/wallet.php` accepts no `user_id`
parameter from the client — it's always resolved from the session, so
there's no way to request someone else's wallet by changing a
parameter.

## Frontend integration

The static site built earlier (`mulaearn/register.html`,
`dashboard.html`, etc.) currently fakes success with a toast message.
To wire it up for real:

1. On page load, `fetch('/api/me.php')` to get a CSRF token and
   current user.
2. On the register form submit, POST to `/api/register.php` with the
   form fields plus the CSRF token instead of showing the "preview
   only" toast.
3. On the dashboard, `fetch('/api/wallet.php')` and render the real
   balance/transactions instead of the hard-coded demo numbers.

## What's been tested

Every one of these was actually run against a live instance of this
code during development, not just written and assumed to work:

- Registration succeeds with valid input; wallet auto-created at zero.
- Registration with `?ref=KellySmith` correctly stores
  `referred_by_user_id` — verified by querying the database directly.
- Registration with a referral code that doesn't exist still succeeds,
  just without a sponsor (no error shown to the user).
- Duplicate username is rejected.
- Weak password (`"abc"`) is rejected by validation before it ever
  reaches the database.
- `/api/wallet.php` returns 401 when not logged in.
- Login works with correct credentials; wrong password is rejected.
- Five failed login attempts locks the account; a sixth attempt is
  rejected even with the *correct* password until the lockout expires.
- `WalletService::credit()` puts money in `pending_balance`, not
  `available_balance`.
- `WalletService::releasePending()` moves it to `available_balance`
  and `lifetime_earnings` — this is the "admin approval" step.
- `WalletService::requestWithdrawal()` correctly blocks a withdrawal
  larger than the available balance.
- `WalletService::credit()` rejects an invalid transaction type before
  touching the database.

## Suggested next slice

Per `BACKEND_PLAN.md`'s build order: affiliate campaigns + link
generation + click tracking (still no money moving), then conversion
recording with an admin-approval step that calls
`WalletService::releasePending()`, then a real payment integration
for withdrawals.

---

# Admin Panel

A separate, server-rendered admin panel lives at `/admin`, completely
isolated from the user-facing site:

- **Own database table** (`admin_users`) — an admin account is never
  a `users` row with a flag flipped.
- **Own session cookie** (`mulaearn_admin_session`, `SameSite=Strict`)
  — a leaked user session can't be replayed against the admin panel
  and vice versa.
- **Own lockout counter**, own CSRF tokens, own login log.
- **No seeded default admin.** There is no hardcoded
  `admin/admin123` anywhere in this project — that's one of the most
  common ways admin panels get compromised. Create the first admin
  with the CLI script:

  ```bash
  php bin/create_admin.php
  ```

  It prompts for username, email, and a password (minimum 12
  characters for admin accounts — stricter than the 8-character
  minimum for regular users).

## Pages

| Page | What it does |
|---|---|
| `/admin/index.php` | Dashboard — live user counts, pending approval/withdrawal totals, queues |
| `/admin/users.php` | Search users, suspend/reactivate |
| `/admin/user_detail.php?id=` | One user's wallet, referral tree, full transaction history |
| `/admin/transactions.php` | Approve or reject pending affiliate commissions, referral bonuses, activity/competition rewards |
| `/admin/withdrawals.php` | Mark a withdrawal completed (**requires** a real payment provider reference — the form won't submit without one) or failed (auto-refunds the user) |
| `/admin/settings.php` | Edit the admin-configurable values (referral bonus, min withdrawal, points conversion) |
| `/admin/logs.php` | Full audit trail of every admin action, plus recent login attempts on the main site |

## Why "requires a reference" matters

The withdrawals page will not let you mark something completed
without typing in a payment provider reference (an M-Pesa
confirmation code, a bank transaction ID). This is deliberate —
it's the difference between "the system pretends money moved" and
"a human confirmed money moved and left a paper trail." Once a real
M-Pesa/bank API is integrated, this becomes an automatic webhook
instead of a manual field, but the audit requirement doesn't change.

## What's been tested (admin panel)

All of this was run against a live instance during development:

- Admin login works; wrong password is rejected; 5 failed attempts
  locks the account (separately from the user-site lockout counter).
- Dashboard renders real counts from the database, not placeholders.
- Approving a pending KSh 450 affiliate commission through the actual
  UI moved it from `pending_balance` to `available_balance` and
  `lifetime_earnings` — verified by querying the database directly
  after the request, not just checking the HTTP response.
- Completing a withdrawal without a reference is rejected server-side
  (not just hidden by the `required` HTML attribute).
- Completing the same withdrawal twice is rejected the second time.
- Suspending a user through `/admin/users.php` actually blocks that
  user from logging into the main site's `/api/login.php` — confirmed
  by attempting a real login as the suspended user afterward.
- Every one of the above actions appears in `/admin/logs.php` with the
  correct admin, action, target, and detail.
- Settings validation rejects a non-numeric value before it reaches
  the database.

---

# Affiliate System (Slice 3)

Campaigns, tracking links, click logging, and conversion recording —
the actual mechanism behind the affiliate marketplace on the static
site.

## The money flow, precisely

1. User clicks **Promote** on a campaign → `getOrCreateLink()` makes
   (or reuses) a unique tracking code for that (user, campaign) pair.
2. They share `mulaearn.com/r.php?c=<code>`. Every click hits
   `public/r.php`, which logs a click (hashed IP, debounced to one per
   60 seconds) and 302-redirects to the advertiser's real URL.
3. When a sale is confirmed — today, a human checking the advertiser's
   own dashboard; later, a webhook — an admin records it on
   `/admin/conversions.php`, **with a required note** on where the
   confirmation came from. This calls `WalletService::credit()`,
   which creates a **pending** transaction. No money is available yet.
4. A separate admin approval on `/admin/transactions.php` (same
   Approvals page from Slice 1) releases it to `available_balance`.

Two separate steps, not one — recording a conversion and approving
its payout are deliberately different actions, so no single click
moves money from "someone said this converted" to "user can withdraw
it."

## New endpoints

| Endpoint | Method | Auth | Purpose |
|---|---|---|---|
| `/api/affiliate/campaigns.php` | GET | No | Browse active campaigns |
| `/api/affiliate/promote.php` | POST | Yes | Get/create your tracking link for a campaign |
| `/api/affiliate/stats.php` | GET | Yes | Your own clicks/conversions/commission — current session user only |
| `/r.php?c=<code>` | GET | No | The actual shareable link — logs a click, redirects to the advertiser |
| `/admin/campaigns.php` | — | Admin | Create campaigns, pause/activate them |
| `/admin/conversions.php` | — | Admin | Record a confirmed conversion for a link (creates a pending transaction) |

## What's been tested (affiliate system)

- Public campaign list loads with no auth.
- Promoting a campaign creates a link; promoting the *same* campaign
  again returns the *same* tracking code rather than creating a
  duplicate.
- Clicking `/r.php?c=...` logs exactly one click and redirects (302)
  to the campaign's real target URL.
- Clicking the same link again immediately is debounced — confirmed
  by counting rows in `affiliate_clicks` directly, not just checking
  the HTTP response.
- An invalid/unknown tracking code returns 404, not a crash.
- Recording a conversion without a confirmation note is rejected.
- Recording a conversion creates a **pending** transaction — checked
  directly in the database that `available_balance` was still 0 and
  `pending_balance` was 450 before approval.
- Approving that transaction on the existing Approvals page moved it
  to `available_balance` and `lifetime_earnings` correctly.
- The user's own `/api/affiliate/stats.php` correctly reflects clicks,
  conversions, conversion rate, and both pending and approved
  commission after the full flow.

**Bug caught and fixed during this round of testing:** `credit()` and
`requestWithdrawal()` had a `string $amount` type hint, but SQLite
returns DECIMAL columns as PHP int/float, not string — so pulling a
campaign's `commission_amount` straight out of the database and
passing it to `credit()` threw a `TypeError` before a single conversion
could be recorded. Fixed by widening both signatures to
`int|float|string` and normalizing to a formatted string internally,
so the bug class can't recur at other call sites either.

## Suggested next slice

Wire the static frontend (`mulaearn/affiliate.html`, `mulaearn/dashboard.html`) to these real
endpoints instead of hardcoded JS arrays and fake toasts — or move to real payment integration for
withdrawals, or role-based admin permission enforcement (the `role`
column exists but nothing checks it yet).

---

# User Referrals, Expanded Admin Controls & Email Notifications (Slice 4)

## Referrals — visible to the user, not just the database

`GET /api/referrals.php` (login required) returns the current session
user's own referral link and everyone who registered under it —
username, status, join date. No user_id parameter accepted; same
pattern as every other user-facing endpoint. A deleted referral shows
as `"Deleted account"` rather than leaking its scrubbed placeholder
username, but still counts toward `referral_count` so the history
stays accurate.

On the static frontend, `mulaearn/dashboard.html` now has a **My
Referrals** section (linked from the top nav) showing the referral
link and a table of referrals, styled to match the rest of the
dashboard. It's demo-populated right now, same as the rest of that
page's stats — the note in the section tells you to point it at
`/api/referrals.php` when the frontend gets wired to the real backend.

## Expanded admin controls (`/admin/user_detail.php`)

| Control | What it actually does |
|---|---|
| Suspend / reactivate account | Existing — blocks login entirely |
| **Suspend / re-enable withdrawals** | New — blocks *only* `/api/withdraw.php` for that user; they can still browse, earn, and log in. Checked both in the API and inside `WalletService::requestWithdrawal()` itself, so nothing bypasses it. |
| **Edit username / email** | Runs through the same validators as self-registration, still enforces uniqueness against every other account. For fixing typos or offensive usernames — not a way around the deletion flow below. |
| **Adjust balance** | Deliberately **not** a raw balance overwrite. See "Why balance adjustment isn't a direct edit" below. |
| **Delete account** | Soft-delete: blocks login, scrubs username/email/phone to an unusable placeholder, frees the username/email for reuse. Refuses to run if the account has a nonzero available balance unless you explicitly check "delete anyway" — deleting an account shouldn't silently strand real money. Transaction history and wallet rows are kept intact and attributable to the (now anonymized) account, because a hard `DELETE` would cascade away financial records that may represent money that already moved. |
| Approve/reject transactions, complete/fail withdrawals | Existing, from Slices 1–3 |

### Why balance adjustment isn't a direct edit

You asked for "admin can edit user dashboard and balance." I didn't
build that as `UPDATE wallets SET available_balance = <admin's number>`
— that would mean a single admin form field could silently overwrite
the one number in this whole system that's supposed to only ever
change through an audited transaction. Instead,
`WalletService::adminAdjustBalance()` takes a **delta** (+500 or -200)
and a **mandatory reason**, writes it as an `admin_adjustment`
transaction, and updates the wallet from that transaction — same
pattern as every other balance change in the app. A negative
adjustment that would take the balance below zero is rejected. The
result is the same practical capability (admin corrects a user's
balance) without breaking the one invariant this whole backend is
built around: nothing changes a balance except a logged transaction.

## Email notifications (`src/Mailer.php`)

A hand-rolled SMTP client (no Composer — this sandbox has no route to
packagist.org) that speaks real EHLO / STARTTLS / AUTH LOGIN / MAIL
FROM / RCPT TO / DATA over a PHP stream socket.

**If `SMTP_HOST` isn't set**, it does not pretend to send — it writes
the email to `storage/mail.log` and returns `sent: false`. Every
caller (currently just `/api/withdraw.php`) reports this honestly
back to the client as `"email_sent": false` rather than claiming a
notification went out that didn't.

`/api/withdraw.php` sends a notification — not a payment
confirmation — to the user's **registered** email (never a
client-supplied address) the moment a withdrawal is requested, with
the exact amount and a transaction reference. The withdrawal itself is
still `pending` until an admin (or eventually M-Pesa's callback)
completes it on `/admin/withdrawals.php`.

Configure via env vars: `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`
(`tls`/`ssl`/`none`), `SMTP_USER`, `SMTP_PASS`, `MAIL_FROM`,
`MAIL_FROM_NAME`.

## What's been tested (this slice)

Every item below was run against a live instance, with results
checked against the actual database or an independent process — not
assumed from reading the code:

- `/api/referrals.php` correctly lists all three referrals of a test
  sponsor, and after one was deleted, correctly shows `"Deleted
  account"` while keeping the count accurate.
- Withdrawal request with SMTP unconfigured: `email_sent: false`,
  and the exact amount (KSh 200.00) verified in `storage/mail.log`.
- Withdrawal request with SMTP configured against a **separate fake
  SMTP server process**: `email_sent: true`, and that independent
  process's own captured output confirms it received the correct
  AUTH credentials, correct `MAIL FROM`/`RCPT TO`, and a correctly
  formatted message body with the exact amount (KSh 425.00) — proof
  the SMTP client is protocol-correct, not just "didn't throw."
- Suspending withdrawals for one user via the admin UI, then
  attempting `/api/withdraw.php` as that user, correctly returns a
  403 — while their account status and login remain unaffected.
- Balance adjustment: a +150 goodwill credit correctly moved
  `available_balance` and `lifetime_earnings`; an attempted -999999
  adjustment was correctly rejected before touching the database; an
  adjustment with an empty reason was correctly rejected.
- Deleting an account with a nonzero balance was correctly refused;
  after zeroing the balance via an adjustment, deletion succeeded and
  the row was correctly scrubbed (`deleted_user_3_<random>`, unusable
  password hash, status `deleted`).
- Editing a username to one already taken by another account was
  correctly rejected with no partial write.
- Every one of the above actions appears correctly in `/admin/logs.php`
  with the right admin, action, target, and detail.

**Bugs caught and fixed during this round of testing** (not shipped
silently):
1. `WalletService::requestWithdrawal()` didn't check
   `withdrawal_suspended` itself — only the API route did. Fixed by
   adding the check inside the service, matching how every other
   safety check in that class works.
2. `database/schema.sql` (MySQL) never had `'admin_adjustment'` added
   to the `transactions.type` ENUM, even though the SQLite schema
   (which doesn't enforce enums) silently allowed it — this would
   have thrown on real production MySQL the first time an admin used
   the feature. Fixed.
3. The local SMTP test harness (`tests/fake_smtp_server.php`) didn't
   explicitly handle `STARTTLS`, causing a false failure that turned
   out to be the test tool's bug, not the Mailer's — `Mailer.php` was
   correctly enforcing the RFC 3207 response code. Fixed the harness
   and re-verified against `SMTP_ENCRYPTION=none`, which is also a
   realistic production path (many internal mail relays don't use
   STARTTLS on their submission port).

## Suggested next slice

Frontend integration (making `dashboard.html`'s referrals/wallet/
withdraw actually call these endpoints instead of showing demo data),
or real M-Pesa withdrawal completion, or role-based admin permissions.

---

# Referrals, Expanded Admin Controls & Email Notifications (Slice 4)

## User-facing: "My Referrals"

`GET /api/referrals.php` (login required) returns the current
session user's own referral link and everyone who registered under
it — username, status, join date. No `user_id` parameter accepted,
same pattern as every other user-facing endpoint. A referral who was
later deleted shows as `"Deleted account"` rather than leaking their
scrubbed placeholder username, but still counts toward
`referral_count` — deleting an account doesn't rewrite history.

The static frontend (`mulaearn/dashboard.html`) now has a matching
**My Referrals** section — demo-styled like the rest of that page,
with the wiring instructions to connect it to this endpoint left as
a comment in the page.

## Admin: what's new on `/admin/user_detail.php`

- **Suspend withdrawals** independently of suspending the whole
  account — the user can still log in and earn, they just can't
  request a payout. Enforced in `WalletService::requestWithdrawal()`
  itself, not only at the API layer, so nothing can bypass it by
  calling the service directly.
- **Edit username / email** — runs through the same validators as
  self-registration and still enforces uniqueness against other
  accounts.
- **Adjust balance** — deliberately NOT a raw balance overwrite. It
  requires a signed amount and a mandatory reason, creates a
  `completed` `admin_adjustment` transaction, and is rejected if a
  negative adjustment would take the balance below zero. This keeps
  the same invariant as every other balance change in this project:
  no code path sets `wallets.available_balance` directly without
  a transaction row justifying it.
- **Delete account** — a soft delete, not `DELETE FROM users`. A hard
  delete would cascade away that user's `transactions` rows via the
  schema's foreign keys, destroying the audit trail for money that
  may have already moved. Instead this blocks login and scrubs
  username/email/phone/password to unusable placeholders, while
  leaving wallet and transaction history intact and attributable.
  Refuses to run if the account has a nonzero available balance
  unless explicitly forced, so deleting an account can't silently
  strand real money.

## Email notifications

`POST /api/withdraw.php` — validates the amount against the
admin-configured minimum, checks account status and the
withdrawal-suspension flag, calls `WalletService::requestWithdrawal()`,
then emails the user's **registered** address (never one supplied by
the client) with the exact amount requested.

`src/Mailer.php` is a small dependency-free raw SMTP client (no
Composer packages — this project's build environment has no route to
packagist.org). If `SMTP_HOST` isn't set, it doesn't pretend to send:
it logs the email to `storage/mail.log` and the API honestly reports
`"email_sent": false`. Configure `SMTP_HOST`, `SMTP_PORT`,
`SMTP_USER`, `SMTP_PASS`, `SMTP_ENCRYPTION` (`tls`/`ssl`/`none`),
`MAIL_FROM`, and `MAIL_FROM_NAME` to send for real.

## What's been tested (this slice)

Everything below was run against a live instance, not just written:

- `/api/referrals.php` returns the correct three referrals for a
  seeded referrer, and correctly shows a deleted referral as
  `"Deleted account"` while preserving the count.
- Withdrawal request with no SMTP configured: logs to `mail.log` with
  the correct recipient and exact amount; API reports `email_sent: false`.
- Withdrawal request WITH SMTP configured: verified by an
  **independent second process** (`tests/fake_smtp_server.php`) that
  received a real socket connection and correctly parsed the AUTH
  credentials, `MAIL FROM`, `RCPT TO`, and message body — confirming
  the exact withdrawal amount (KSh 425.00) reached the wire, not just
  that the PHP code returned success.
- Suspending withdrawals for one user via the admin UI, then
  confirming `/api/withdraw.php` actually rejects that user's request
  (not just that the admin page shows a success flash).
- Balance adjustment: positive credit applied correctly; a negative
  adjustment larger than the available balance is rejected; a missing
  reason is rejected.
- Account deletion: rejected while the account holds a nonzero
  balance; succeeds and correctly scrubs PII once the balance is
  zeroed; the deleted user's referrer still sees them (as "Deleted
  account") in their referral list afterward.
- Username/email edit: succeeds for a valid change; correctly
  rejected when the new username collides with another account.
- Every action above appears in `/admin/logs.php` with the correct
  admin, action, target, and detail.

## Bugs found and fixed while testing this slice

1. `WalletService::requestWithdrawal()` didn't check
   `withdrawal_suspended` itself — only the API route did. Fixed by
   adding the check inside the service, matching how every other
   guard in that class works (defense-in-depth, not caller-trusted).
2. `database/schema.sql` (MySQL) never had `'admin_adjustment'` added
   to the `transactions.type` ENUM, even though the SQLite mirror
   (which doesn't enforce enums) silently accepted it. Would have
   broken the very first balance adjustment on real MySQL. Fixed.
3. The local SMTP test harness (`tests/fake_smtp_server.php`) didn't
   explicitly handle `STARTTLS`, falling through to a generic
   `250 OK` — but `Mailer.php` correctly expects `220` per RFC 3207
   and rightly refused to proceed. This was a test harness bug, not a
   `Mailer.php` bug; fixed the harness and confirmed the full
   plaintext SMTP path with `SMTP_ENCRYPTION=none`. Testing real
   STARTTLS end-to-end needs an actual TLS-capable mail server — this
   harness can't fake a certificate.

## Suggested next slice

Wire `dashboard.html`'s My Referrals section and withdrawal form to
the real endpoints, add role-based enforcement for the admin `role`
column, or move on to the M-Pesa B2C integration for withdrawals
(scoped but not yet built).

---

# Frontend-Backend Integration & Auto-Refreshing Dashboard (Slice 5)

The static frontend (previously demo-only) is now served from
`public/` alongside the API, and is genuinely wired to it — not
CORS-bridged from a separate origin. That choice was deliberate: same-origin
means session cookies just work with no CORS configuration to get
wrong, which is exactly the kind of thing that quietly becomes a
security hole if done carelessly.

## What's real now

- **`login.html`** — didn't exist before; the frontend had registration
  but no way to sign back in.
- **`register.html`** — posts to `/api/register.php` for real, shows
  actual server-side validation errors, redirects to the dashboard on
  success.
- **`dashboard.html`** — balance, the four earnings breakdowns (total,
  today's, affiliate, referral), the transaction table, and the
  referrals tab all render from `/api/wallet.php` and
  `/api/referrals.php`. The withdrawal form posts to `/api/withdraw.php`.
  Two stat cards (Mula Points, Competition rank) are honestly marked
  "coming soon" rather than showing invented numbers — no backend
  exists for either yet.
- **`affiliate.html`** — the campaign grid loads from
  `/api/affiliate/campaigns.php` instead of a hardcoded JS array; the
  Promote button generates a real tracking link via
  `/api/affiliate/promote.php` (redirecting to login first if you're
  not signed in); the performance summary cards pull from
  `/api/affiliate/stats.php`.
- **`assets/api.js`** — a small shared fetch layer (`Api.me/get/post/
  requireAuth/logout`) every page loads before `app.js`.

**Still demo, honestly**: `games.html`'s trivia and leaderboard (no
backend exists for games/trivia), and the affiliate performance chart
on `affiliate.html` (the summary cards above it are real; the
day-by-day trend line has no time-series endpoint behind it yet).

## Auto-refreshing dashboard ("realtime")

Calling this "realtime" would overclaim — there's no websocket or
server-push here, and building one would be a materially bigger
project than what was asked. What's actually built: `dashboard.html`
polls `/api/wallet.php` and `/api/referrals.php` every 20 seconds,
pauses while the browser tab is hidden (`document.hidden`), and
refreshes immediately when you switch back to it via the
`visibilitychange` event — so it's never quietly stale while you're
actually looking at it, and never wastes requests while you're not. A
small "Live / Updated Xs ago" indicator next to the logout button
reflects this honestly rather than pretending the connection is
persistent.

## New backend additions this slice

- `WalletService::summaryFor()` — the four-way earnings breakdown
  (total/today/affiliate/referral), computed with direct SQL
  aggregates over the *entire* transaction history, not derived from
  `recentTransactions()`'s limited page (which would silently
  undercount past the first 10 transactions).
- `AffiliateService::campaignAggregates()` — real, platform-wide
  click/conversion counts per campaign (not per-user), shown on the
  public marketplace cards.
- `AffiliateService::statsForUser()` extended with
  `rejected_conversions` and `total_earnings`.

## Bugs found and fixed while testing this integration

Every one of these was caught by actually running the code against a
live server and checking the real HTTP response — not by reading the
JS and assuming it would work:

1. **`register.html` and `affiliate.html` never loaded `api.js`.**
   They load the shared `app.js`, which now calls `Api.post()` /
   `Api.me()` — but without `api.js` loaded first, `Api` would be
   undefined the moment someone submitted the registration form or
   clicked Promote. Caught by grepping every page for "uses `Api.`"
   against "includes `api.js`" and finding a mismatch, not by
   guessing. Fixed on all six pages for consistency.
2. **Empty aggregate maps serialized as `[]` instead of `{}`.** PHP's
   `json_encode()` can't tell an empty associative array from an empty
   list, so `AffiliateService::campaignAggregates()` returned
   `{"clicks": [], "conversions": []}` as JSON on a fresh install with
   no click data yet — the wrong type for a JS object lookup by
   campaign ID. It happened not to break the frontend (a `|| 0`
   fallback masked it), but was fixed properly at the source by
   casting to `(object)` when empty, rather than relying on every
   future consumer remembering to guard against it.
3. **`/api/wallet.php` never actually computed the earnings breakdown
   the new dashboard depends on.** `WalletService` only had
   `balanceFor()` (available/pending/lifetime), but `dashboard.html`'s
   JS was written to expect `today_earnings`, `affiliate_earnings`,
   and `referral_earnings` too. Building `summaryFor()` and rewiring
   `wallet.php` to use it, then verifying the real numbers
   (`480` total, `450` correctly isolated as affiliate-only, `0`
   referral since none exist) against a database I could inspect
   directly, is what caught this — not code review.

## What's been verified end-to-end (this slice)

All against a live PHP server, checked against real HTTP responses or
direct database queries:

- Every static asset (`index.html` through `games.html`, `api.js`,
  `app.js`, `styles.css`, `logo.png`) resolves with HTTP 200 through
  the same server that serves the API.
- `api.js` loads before `app.js` on every single page — checked by
  line number, not assumed.
- Registration with the exact payload shape the real form sends
  (concatenated country code + number for phone, `ref` pulled from
  the URL) correctly creates the account, captures the referral, and
  auto-logs-in — confirmed via `/api/me.php` returning the new session
  immediately after.
- `/api/affiliate/campaigns.php`'s response shape matches the
  JavaScript's field names exactly (`brand_name`, `title`,
  `commission_amount`, etc.) — checked field-by-field, not assumed
  from having written both sides.
- The full real promote-and-click loop: login → generate a tracking
  link via the same call `openPromote()` makes → click it → confirm
  the platform-wide aggregate count went from 0 to 1 for that specific
  campaign.
- An anonymous (logged-out) promote attempt gets a clean 401 JSON
  response, which is what triggers the frontend's "log in first"
  redirect rather than a broken/silent failure.
- A full withdrawal request through the exact call `submitWithdrawal()`
  makes, followed immediately by re-fetching `/api/wallet.php`,
  correctly shows the reduced available balance and the new pending
  withdrawal transaction — proving the "auto-refresh picks up your own
  actions immediately" behavior actually works, not just the 20-second
  poll.

## Known limitation of this testing

There's no headless browser available in the environment this was
built in, so none of the above was verified by actually clicking
through the rendered pages in a browser — every check was at the
HTTP/API level, plus careful manual tracing of the JavaScript logic.
**A manual click-through after deploying this is genuinely worth
doing** before treating the frontend integration as fully proven,
particularly around focus states, the promote modal's QR code
rendering, and the auto-refresh indicator's visual behavior.

## Suggested next slice

Wire `games.html` to a real trivia/leaderboard backend (doesn't exist
yet), add the affiliate performance trend chart's time-series
endpoint, or move to the M-Pesa B2C integration for withdrawals.

