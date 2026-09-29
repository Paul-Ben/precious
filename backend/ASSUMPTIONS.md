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
| C8 | One monorepo `precious` with `backend/` and `frontend/` (replaces C1's two repos) |
| C9 | P1–P14 below accepted as proposed; all numeric values are editable in Staff → Property & policies |
| C10 | **The payer covers online gateway fees** (29 Sep 2026). A processing fee is added on top so the hotel receives the full amount; switchable in Property & policies |

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

## Business rules — confirmed 29 Sep 2026 (P1–P14 accepted as proposed)

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

## Implemented in M2a on assumption

| # | Assumption | Where |
|---|---|---|
| A10 | Room rate is per room per night (not per person); children do not change the price | `PricingService` |
| A11 | VAT and service charge are added on top of the rate (rates are shown ex-tax, total incl. tax) | `PricingService` |
| A12 | A booking needs at least one adult per room | `ReservationService::prepare` |
| A13 | Staff can set an unpaid hold of 5 min–72 h (defaults to the 30-min online hold) | `hotel.defaults.staff_hold_max_minutes` |
| A14 | Online bookings: max 5 rooms, 30 nights, up to 365 days ahead | `config/hotel.php` |
| A15 | Anonymous online bookings always create a new guest profile (never attached to an existing one by email — prevents strangers adding to someone's history); signed-in customers reuse their own profile; staff pick an existing guest or create one. Staff can merge duplicates later (M6) | `ReservationService::resolveGuest` |
| A16 | Any staff member with `guests.documents.view` can upload/verify/delete ID documents; every download is audited | routes / `GuestService` |

## Implemented in M2b on assumption

| # | Assumption | Where |
|---|---|---|
| A17 | Processing fee uses each gateway's published Nigerian local rate (checked 29 Sep 2026): Paystack 1.5 % + ₦100 (₦100 waived under ₦2,500), capped at ₦2,000; Flutterwave 2.0 %, no cap. Editable per gateway. International cards cost the hotel more; the difference is absorbed | `config/payments.php`, `FeeSchedule` |
| A18 | Processing fees are not refunded (consistent with P6) | `RefundService` |
| A19 | Starting an online payment keeps an unpaid hold alive for at least 15 more minutes, at most 3 times per booking | `PaymentService::extendHold` |
| A20 | Money that arrives after a hold expired revives the booking if its rooms (or same-type rooms) are still free; otherwise it is recorded and flagged for refund | `ReservationLedger` |
| A21 | Refunds are paid out manually (gateway dashboard, cash or transfer) and then recorded; automatic gateway refunds can come later. At or below ₦100,000 the requester's own `payments.refund` permission is the approval | `RefundService` |
| A22 | Front desk can take part payments; only reaching the deposit confirms a booking. Bank transfers need a reference | `PaymentService::recordManual` |
| A23 | Pending online payments are re-checked every 10 minutes and marked ABANDONED after 48 h | `payments:reconcile` |

## Check-in / check-out rules — confirmed 30 Sep 2026

| # | Rule | Where |
|---|---|---|
| P15 | An ID must be on the main guest's profile, or its type and number recorded at the desk, before check-in. Admins can switch this off | `require_id_at_check_in`, `StayService::checkIn` |
| P16 | Early check-in (before 14:00) is free if a clean room is ready. No fee for now | `StayService::checkIn` |
| P17 | Leaving early: unused booked nights are still charged; the remaining nights are released for sale. A manager can reduce the bill with an adjustment | `StayService::checkOut`, `ADJUSTMENT` charges |
| P18 | No check-out with money owed, except by someone with `checkouts.override_balance` (Hotel Manager, Administrator) with a reason. The debt stays on record | `StayService::checkOut` |

## Implemented in M2c on assumption

| # | Assumption | Where |
|---|---|---|
| A24 | Check-in is possible from the arrival day until the day before departure, for the whole booking at once; a room must be AVAILABLE or RESERVED (clean) | `StayService` |
| A25 | Extra nights are billed at the booked nightly rate + accommodation VAT, as bill items | `StayService::extend` |
| A26 | Guests past their departure date must be extended before check-out (so extra nights are billed) | `OVERSTAY` |
| A27 | The late check-out fee is added once, at the rate that applies at the first check-out attempt; it can be waived with a reason (audited) | `StayService::settleLateFee` |
| A28 | Services use the global service-charge (10 %) and VAT (7.5 %) rates, switchable per service. Reductions (adjustments) need `discounts.approve` and are entered tax-inclusive | `FolioService` |
| A29 | Bill items can only be added or voided while the guest is in house; voided lines stay visible | `FolioService` |

## Bar rules — confirmed 1 Oct 2026

| # | Rule | Where |
|---|---|---|
| P19 | Menu prices are before tax: service charge (10 %) and VAT (7.5 %) are added on the bill, VAT on (items − discount + service charge) | `TabService::recalculate` |
| P20 | With an email on the bill, the customer gets the running bill + secure pay link after each order, and the final bill on close | `BarBillNotification` |
| P21 | A waiter can cancel their own order until the bar accepts it; after that only `bar.orders.cancel` (Hotel Manager and up), with a reason. Delivered items are not cancelled — use a discount | `TabService::cancelOrder` |
| P22 | Bar discounts need `discounts.apply` (Hotel Manager, Administrator) and a reason; audited | `TabService::applyDiscount` |

## Implemented in M3 on assumption

| # | Assumption | Where |
|---|---|---|
| A30 | One bill (tab) per visit; several tabs may share a table; the table is freed when its last tab closes | `TabService` |
| A31 | A bill can be closed only when every order is delivered or cancelled and nothing is owed; an empty bill is cancelled | `TabService::close` |
| A32 | Charge to room needs `bar.orders.charge_to_room`, all orders delivered and nothing paid yet; the whole bill moves to the guest's hotel bill as a BAR line (not voidable there — use a reduction). The match is room number + any guest surname on a checked-in booking; errors don't reveal who is in a room | `TabService::chargeToRoom` |
| A33 | Stock tracking stays off (P11); products have a sold-out switch that bartenders can use | `bar/products/{id}/availability` |
| A34 | Live bartender/waiter updates poll every 4–10 s; Reverb push (events already emitted) is switched on in M4 | `BarOrderChanged` |
