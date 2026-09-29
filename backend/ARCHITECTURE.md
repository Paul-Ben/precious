# Architecture

See the master spec (§4–5, §46–52) for the full vision. This document describes what is
built and the conventions every later module follows.

## System context

```text
Browser ──HTTPS──► Next.js (App Router)
                     │  • renders public site, customer portal, staff portal
                     │  • /api/bff/*  → server-side proxy to Laravel
                     │  • holds the API token in an httpOnly cookie
                     ▼
                Laravel API  /api/v1  ──► PostgreSQL
                     │                ──► Resend (email)
                     │                ──► Cloudflare R2 (files, from M2)
                     │                ──► Paystack / Flutterwave (from M2)
                     ├─ queue worker  (notifications, webhooks, reports)
                     └─ scheduler     (token pruning, reservation expiry, reminders)
```

**Laravel is authoritative** for every business rule, calculation and permission check.
Next.js only presents data and never duplicates financial logic.

## Authentication model (BFF)

1. The browser posts credentials to `POST /api/bff/auth/login` (Next.js).
2. Next.js forwards to Laravel. Laravel returns a Sanctum token.
3. Next.js stores the token in an **httpOnly, SameSite=Lax** cookie and strips it from the
   JSON returned to the browser. JavaScript can never read the token (XSS-resistant).
4. Every later `/api/bff/*` call is forwarded with `Authorization: Bearer <token>`.
5. State-changing BFF calls must be same-origin (Origin / Sec-Fetch-Site check → CSRF-safe).

Why not Sanctum's cookie SPA mode? The API and frontend will live on different Railway
domains; bearer tokens behind a BFF work across domains and keep Server Components simple.

Token lifetimes: customers 7 days, staff 12 hours (`config/security.php`).

## Two-factor authentication (email OTP)

* Mandatory for users holding **Super Administrator** or **Administrator**
  (`security.two_factor.required_roles`); any user can opt in (`two_factor_enabled`).
* Login returns `{ two_factor_required: true, two_factor: { challenge_id, destination } }`
  instead of a token. A 6-digit code is emailed immediately (not queued).
* Codes: hashed, single-use, 10-minute expiry, locked after 5 wrong attempts,
  60-second resend cooldown, rate limited per IP and per challenge.
* Tokens record `two_factor_confirmed`. Middleware `two_factor` rejects any token that
  skipped 2FA for a user who now requires it (e.g. was just promoted to Administrator);
  such tokens are also revoked when the role is granted.

## Authorisation (RBAC)

* Permissions are defined **in code** — `app/Domain/Identity/PermissionCatalog.php` — and
  synced to the DB by `PermissionSeeder`. Roles are data and fully configurable.
* A user can hold **many roles**; effective permissions are the union.
* Routes are protected with `permission:<name>` middleware (any-of with `|`).
* `Super Administrator` passes every check via `Gate::before`.
* Anti-escalation (`PrivilegeGuard`): non-super-admins can only grant roles/permissions
  they hold themselves; only a Super Administrator can grant/remove Super Administrator
  or manage another Super Administrator; nobody can change their own roles or suspend
  themselves; the last active Super Administrator is protected.
* Permission changes take effect on the very next request (no session caching).

## Code layout

```text
app/
  Domain/                 business logic, framework-light services
    Identity/             AuthService, TwoFactorService, TokenIssuer, UserService,
                          RoleService, PrivilegeGuard, PermissionCatalog, RoleDefaults
    Audit/                AuditService (append-only, redacting)
    Payments/             GatewayRegistry, PaymentGatewaySettingsService
  Enums/                  UserType, UserStatus, GatewayMode
  Exceptions/             BusinessRuleException → 4xx with machine-readable code
  Http/
    Controllers/Api/V1/   thin controllers: validate → call service → ApiResponse
    Middleware/           request id, security headers, active, 2FA, password change,
                          staff, permission
    Requests/             FormRequests (validation only; authorisation in middleware)
    Resources/            API Resources (never expose secrets)
  Models/
  Notifications/
  Support/ApiResponse.php the single place that shapes JSON envelopes
```

Rules followed by every module:

* Controllers coordinate; services own business logic; all multi-write operations run in
  `DB::transaction`.
* Every sensitive mutation writes an audit record through `AuditService`.
* Services throw `BusinessRuleException` for rule violations — never return `false`.
* Money (from M2) is `numeric(14,2)` in PostgreSQL and `brick/math`-style decimal strings in
  PHP — never floats.

## Cross-cutting

| Concern | Implementation |
|---|---|
| Correlation | `X-Request-Id` on every response; stored in logs context and audit rows |
| Errors | Unified JSON envelope, no stack traces unless `APP_DEBUG=true` |
| Rate limiting | `api` 120/min per user/IP; `auth` 5/min per email+IP, 20/min per IP; `two-factor` per IP and per challenge |
| Queues | `database` driver locally, Redis recommended in production |
| Scheduler | `routes/console.php` — prune expired tokens, 2FA challenges, reset tokens |
| Realtime | Laravel Reverb — introduced with the bar module (M4) |
