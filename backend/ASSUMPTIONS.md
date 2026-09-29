# Assumptions & open decisions

The spec says: *"Do not invent business rules. If a rule is missing, document the
assumption before implementing it."* This is that list. Items marked **Confirmed** came
from the product owner; everything else is a **proposed default** — please confirm or
change before the milestone that needs it.

## Confirmed (28 Sep 2026)

| # | Decision |
|---|---|
| C1 | Two repos inside `C:\Users\O D G\Herd\precious`: `backend` and `frontend` |
| C2 | Local PostgreSQL (tests also use PostgreSQL) |
| C3 | Build M0 + M1 first, then stop for review |
| C4 | Paystack **and** Flutterwave. No keys yet → admins enter test/live keys in the UI; stored encrypted |
| C5 | Email: Resend. Files: Cloudflare R2. Realtime: Laravel Reverb |
| C6 | Placeholder branding and photos until real assets are supplied |
| C7 | 2FA via email OTP; mandatory for administrators |

## Implemented in M1 on assumption

| # | Assumption | Where |
|---|---|---|
| A1 | "Admins" who must use 2FA = **Super Administrator** and **Administrator**. Others may opt in (per user) | `config/security.php` |
| A2 | Customers do not need to verify their email to sign in (MVP) | `AuthService::registerCustomer` |
| A3 | One account per email. A staff member who also wants a guest account needs a different email | `users.email` unique |
| A4 | Customer and staff sign in on separate pages; a staff account cannot use the customer login and vice versa | `AuthController` |
| A5 | Token lifetime: customers 7 days, staff 12 hours (one shift) | `config/security.php` |
| A6 | Password policy: ≥10 chars, upper + lower case, number, symbol; breached-password check in production | `AppServiceProvider` |
| A7 | Permissions are code-defined; roles are editable. `Administrator` always receives every permission (like Super Administrator, but cannot manage Super Administrators) | `RoleSeeder`, `PrivilegeGuard` |
| A8 | Placeholder brand name "Precious Hotel & Bar", timezone Africa/Lagos, currency NGN | env / config |
| A9 | Waiters hold `bar.orders.charge_to_room` by default; Service Agents do not | `RoleDefaults` |

## Proposed defaults for later milestones — please confirm

| # | Topic | Proposed default | Needed by |
|---|---|---|---|
| P1 | VAT | 7.5 % on accommodation, services and bar (configurable per product/service) | M2/M3 |
| P2 | Service charge | 10 % on bar and room service, 0 % on accommodation (configurable) | M3 |
| P3 | Check-in / check-out times | 14:00 / 12:00 | M2 |
| P4 | Late checkout fee | 12:00–18:00 = 50 % of nightly rate; after 18:00 = full night | M2 |
| P5 | Unpaid reservation hold | 30 minutes, then `EXPIRED` | M2 |
| P6 | Cancellation / refund | > 48 h before arrival: deposit refunded (gateway fees not refunded). ≤ 48 h or no-show: deposit forfeited | M2 |
| P7 | No-show | Marked `NO_SHOW` at 23:59 on arrival day if not checked in | M2 |
| P8 | Check-in payment condition | At least the configured deposit paid; balance settled at checkout | M2 |
| P9 | Charge to room verification | Waiter enters room number + guest surname; system matches an active stay; guest signs the printed/emailed slip. Optional 4-digit guest PIN later | M4 |
| P10 | Walk-in bar customers | Phone/email optional. With email → bill + pay link emailed; without → pay on the spot only | M4 |
| P11 | Inventory | Architecture in place, activation deferred to Phase 2 unless you need stock control at launch | M4/M5 |
| P12 | Single property | One hotel for now; tables keep `property_id` for future multi-property | M2 |
| P13 | Receipts | Sequential numbers per year, e.g. `RCP-2026-000123` | M2 |
| P14 | Refund approval | Refunds need `payments.refund`; refunds above ₦100,000 also need a second approver | M2/M6 |
