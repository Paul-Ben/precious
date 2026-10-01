# Testing

```powershell
composer test                      # all tests
php artisan test --filter=TwoFactor
php artisan test --parallel        # optional
```

Tests run against the **PostgreSQL** database `precious_testing` (see `phpunit.xml`) so
constraints, triggers and partial indexes behave exactly like production. Each test runs in
a transaction (`RefreshDatabase`) with `CoreSeeder` loaded.

## Coverage in M1 (77 tests)

| Suite | Covers |
|---|---|
| `Unit/PermissionCatalogTest` | Catalog uniqueness, spec permissions present, role defaults valid, 11 roles |
| `Feature/HealthTest`, `ApiConventionsTest` | Envelope, 404/401/422 formats, request id, security headers, no stack traces |
| `Feature/Auth/RegistrationTest` | Customer registration, policy, case-insensitive uniqueness, mass-assignment safety |
| `Feature/Auth/LoginTest` | Portals, wrong password audit, enumeration, suspended, logout, expiry, rate limit |
| `Feature/Auth/TwoFactorTest` | Admin 2FA, opt-in, single use, expiry, lockout, hashing, resend cooldown, non-2FA token rejection/revocation |
| `Feature/Auth/PasswordTest` | Forced change, change revokes other sessions, forgot/reset flow |
| `Feature/Rbac/*` | Multiple roles (spec test 14), immediate revocation (15), unauthorised staff (16), escalation guards, role CRUD |
| `Feature/Settings/PaymentGatewaySettingsTest` | Encryption at rest, masking, keep-on-blank, prefix checks, enable rules, single default, audit without secrets, connection test (HTTP faked) |
| `Feature/AuditLogTest` | Immutability (model + DB trigger), redaction, access control (spec test 17) |

Spec "critical tests" 13–17 are covered now; 1–12 arrive with reservations, payments,
billing and the bar (M2–M4).

## Added in M2a (+42 tests)

| Suite | Covers |
|---|---|
| `Unit/MoneyTest` | Kobo conversion, half-up percentages, formatting |
| `Feature/Hotel/AvailabilityTest` | Overlaps, back-to-back stays, blocks, out-of-service rooms, **exclusion constraint rejects a direct overlapping insert** |
| `Feature/Hotel/PublicBookingTest` | Quote maths (VAT 7.5 %, deposit 30 %), booking creates `PENDING_PAYMENT` + hold, last-room race → `ROOM_UNAVAILABLE`, validation, lookup token, customer linking, hold expiry releases rooms, no-shows |
| `Feature/Hotel/StaffReservationTest` | Permissions, walk-in/phone bookings, existing guest, cancel rules, front-desk summary |
| `Feature/Hotel/RoomManagementTest` | Room types/rooms CRUD, status changes, blocks vs bookings, delete protection, image upload |
| `Feature/Hotel/GuestTest` | Guest CRUD/search, private document storage, masked numbers, audited downloads |
| `Feature/Hotel/PropertySettingsTest` | Policy overrides, validation, audit |

Spec critical test **1 (no double booking)** is now covered.

## Added in M2b (+33 tests, 152 total)

| Suite | Covers |
|---|---|
| `Unit/FeeScheduleTest` | Paystack/Flutterwave fee maths, gross-up leaves the hotel exactly the amount due, ₦100 waiver, ₦2,000 cap |
| `Feature/Payments/OnlinePaymentTest` | Options with fees, fee pass-through switch, Paystack initialize (amount in kobo, callback), confirm on verified deposit, receipt + email, **credited once across redirect/webhook/reconcile**, webhook signature + dedupe, payload never trusted, declined, short amount, late payment revive vs re-sold room, expired hold, reconcile/abandon, customer ownership |
| `Feature/Payments/FlutterwaveTest` | Gateway choice, 2 % fee, naira amounts, `verif-hash` webhook |
| `Feature/Payments/DeskPaymentTest` | Cash/POS/transfer, part payments, validation, permissions, cancelled bookings, daily cash-up, search |
| `Feature/Payments/RefundTest` | Auto-approval under ₦100k, **P14 second approver**, self-approval blocked, refundable limit, reject, completion updates the booking |
| `Feature/Payments/GatewayFeeSettingsTest` | Fee overrides, reset, validation, audit |

No test calls the real Paystack/Flutterwave APIs (`Http::fake`).

## Added in M2c (+23 tests, 175 total)

| Suite | Covers |
|---|---|
| `Feature/Stays/CheckInTest` | Arrival-day check-in, P8 deposit, P15 ID rule and switch, room not ready → choose another, permissions, in-house list |
| `Feature/Stays/StayChangesTest` | Extend (billing, blocked by next booking), room move splits the booking, services with SC + VAT, other items, voids, adjustments need discount approval, services price list permissions |
| `Feature/Stays/CheckOutTest` | Settled check-out + final bill + email, P18 balance block / manager override, P4 50 % and 100 % late fee (charged once), waiver, P17 early departure frees the room, overstay |

Stay tests freeze time (`travelTo`) at fixed hotel-time moments so late fees are deterministic.

## Added in M3 (+16 tests, 191 total)

| Suite | Covers |
|---|---|
| `Feature/Bar/BarOrderFlowTest` | Open bill, order totals (P19), running-bill email (P20), walk-ins, sold out, bartender queue flow + permissions, P21 cancelling, P22 discounts, role checks |
| `Feature/Bar/BarSettlementTest` | Close rules (pending orders, balance), desk payment + receipt + cash-up, empty bill, P9 charge to room (surname/room checks, hotel bill line), customer pay link via Paystack |
| `Feature/Bar/BarCatalogTest` | Menu and tables admin, waiter read-only, table with open bill |

## Added in M4 (+4 tests, 195 total)

| Suite | Covers |
|---|---|
| `Feature/Reports/ReportsTest` | Occupancy, room revenue, ADR/RevPAR, bar sales and top products, payments by method, daily rows, range clipping, CSV exports (bar bills, payments, reservations), permissions, range validation |

## Added in M5 (+18 tests, 213 total)

| Suite | Covers |
|---|---|
| `Feature/Staff/StaffRecordsTest` | Employee numbers (existing and new accounts), editing records, P23 leaving (sign-in blocked, future shifts cancelled), private photos, permissions |
| `Feature/Staff/ShiftTest` | Standard and overnight shifts, P24 overlaps, P27 emails on assign/change/reassign/cancel, started shifts locked, copy week, permissions |
| `Feature/Staff/AttendanceTest` | P25 clock-in window and lateness, P28 colleagues, P26 absent + auto clock-out, manager corrections with reason, summary hours and CSV |

## Added in M6 (+12 tests, 225 total)

| Suite | Covers |
|---|---|
| `Feature/Finance/ExpensesTest` | P29/P30 auto-approval up to the limit, approval by someone else, rejection, P31 edit vs void, validation, private receipts, permissions |
| `Feature/Finance/DailyClosingTest` | P33 expected cash by method (pending cash expenses included), note on differences, locked day (cash expenses, voids, desk payments), admin-only reopen, closing again |
| `Feature/Finance/FinanceReportsTest` | P32 revenue / expenses / net position and days, outstanding bills, P34 CSV and Excel exports, permissions |

## Conventions

* One behaviour per test, named as a sentence.
* Use `$this->staff('Role', …)`, `$this->customer()`, `$this->actingAsUser($user)` from
  `tests/TestCase.php` — they issue real Sanctum tokens.
* Fake external services: `Notification::fake()`, `Http::fake()`. No real network in tests.
* A module is not done until its API tests pass in CI.
