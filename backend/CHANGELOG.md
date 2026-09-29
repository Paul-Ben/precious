# Changelog

## [0.5.0] — 2026-10-02 — M3 Bar / POS

### Added
- Bar menu (categories, products, sold-out switch), tables, touch-friendly waiter POS.
- Bills (tabs) with several orders; bartender live queue (New → Accepted → Preparing → Ready);
  waiter delivers.
- Service charge + VAT on bar bills (P19), discounts (P22), order cancelling rules (P21).
- Payments at the table (cash/POS/transfer) with receipts; customer pay link by email with
  Paystack/Flutterwave (P20); charge to room by room number + surname onto the guest's hotel
  bill with a printable signature slip (P9).
- Payments service generalised for bar bills; bar money in the daily cash-up.
- `BarOrderChanged` broadcast event (for Reverb in M4). 16 new tests (191 total).

## [0.4.0] — 2026-10-01 — M2c Check-in / stays / check-out

### Added
- Check-in with room assignment or change, ID capture (P15), deposit check (P8); rooms become
  OCCUPIED and a guest stay opens.
- Guest bill: hotel services price list, other items, reductions (discount approval), voids;
  reservation `grand_total` / `balance` include extras; payments and receipts use the full bill.
- Extend stay (extra nights billed), move rooms mid-stay.
- Check-out: bill preview, late check-out fee (P4) with waiver, balance rule with manager
  override (P18), early departure releases nights (P17), final bill FOL-YYYY-NNNNN emailed,
  rooms to DIRTY.
- New permission `checkouts.override_balance` (granted to Hotel Manager by migration).
- 23 new tests (175 total).

## [0.3.0] — 2026-09-30 — M2b Payments

### Added
- Paystack and Flutterwave hosted checkout for deposits and balances (guest link or customer
  account), with the processing fee added for the payer (owner decision) using editable
  per-gateway fee schedules.
- Server-side verification on redirect, signed + deduplicated webhooks, and a 10-minute
  reconciliation job; each payment is credited exactly once.
- Deposit confirms the reservation; late payments revive the booking if rooms are free,
  otherwise they are flagged for refund.
- Desk payments (cash, POS, bank transfer), numbered receipts (RCP-YYYY-NNNNNN) emailed to the
  guest, daily cash-up, payments search.
- Refund workflow with P14 second approval above ₦100,000.
- 33 new tests (152 total).

## [0.2.0] — 2026-09-29 — M2a Hotel core

### Added
- Property, departments, amenities and editable hotel policies (deposit, hold time, VAT,
  service charge, check-in/out times, cancellation window, limits).
- Room types with rates, occupancy, amenities and photos; rooms with status board and
  date-range blocks.
- Guest profiles with private, encrypted-number ID documents and audited downloads.
- Availability search and price quotes (integer-kobo money maths).
- Public booking (guest checkout or signed-in customer) → `PENDING_PAYMENT` with 30-min hold;
  staff bookings for phone/walk-in/front desk; customer "my reservations" and cancellation.
- No double booking: row locks + PostgreSQL exclusion constraint with retry on the next room.
- Scheduler: `reservations:expire-holds` (every minute), `reservations:mark-no-shows` (23:59).
- Gapless document numbers (`RES-2026-00001`).
- 42 new tests (119 total).

 — 2026-09-28 — M0 + M1 Foundation

### Added
- Laravel 13 API skeleton on PostgreSQL with `/api/v1` prefix, standard JSON envelope,
  unified exception rendering, request ids, security headers, health endpoint.
- Customer registration/login, staff login, logout, forgot/reset password, change password.
- Temporary passwords for staff with forced change on first login.
- Email OTP two-factor authentication, mandatory for Super Administrator and Administrator.
- RBAC with 59 code-defined permissions, 11 seeded system roles, multiple roles per user,
  role CRUD, permission sync, user management (create staff, roles, suspend, reactivate,
  temporary password).
- Anti-privilege-escalation rules.
- Append-only audit log with DB trigger and secret redaction.
- Payment gateway settings for Paystack and Flutterwave (encrypted test/live keys, mode,
  enable/default, connection test).
- Resend mail and Cloudflare R2 disk configuration.
- 77 PHPUnit tests; GitHub Actions CI.
- Project documentation and assumptions register.
