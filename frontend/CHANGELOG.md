# Changelog

## [0.8.0] — 2026-10-05 — M6 Finance

### Added
- Finance → Finance overview: revenue, expenses, net position, outstanding, by-day table with
  closing status, downloads (CSV / Excel), print.
- Finance → Expenses: record (with receipt), filters, approve / reject, edit while pending,
  void with reason, categories.
- Finance → Daily closing: figures by method, cash drawer, count and close, reopen (admins),
  printable sheet with signature lines.
- Finance → Outstanding bills.

## [0.7.0] — 2026-10-04 — M5 Staff & shifts

### Added
- People → Staff: directory with search and filters; staff record page (department, position,
  status, dates, emergency contact, notes, photo).
- People → Rota: weekly grid by person, add / edit / cancel shifts, standard shifts editor,
  copy last week, department filter, print.
- People → Attendance: needs-attention list with record/correct (reason required), hours by
  person, CSV.
- My shifts (every staff member): upcoming shifts, Clock in / Clock out, who you're working with.

## [0.6.0] — 2026-10-03 — M4 Realtime & reports

### Added
- Live bar queue, bills and table map over Laravel Reverb (`laravel-echo` + `pusher-js`,
  auth through the BFF); slow polling while live, fast polling as fallback.
- Staff → Reports: date presets and custom range, KPI tiles (occupancy, room revenue,
  ADR/RevPAR, bar sales, money received), revenue-by-day chart with table view, bar and
  payment breakdowns, top products, bills by waiter, CSV downloads (with `reports.export`).

### Changed
- Pay-link, payment result and booking confirmation pages send no referrer (tokens in URLs).

## [0.5.0] — 2026-10-02 — M3 Bar / POS

### Added
- Staff → Bar: table grid, open bill, touch menu with categories/search, cart with notes,
  send to bar; running bill with delivery, cancel, payment, charge to room, discount,
  email and print (bill / room-charge signature slip).
- Staff → Bar queue: four-column live board (auto-refresh), sold-out switches.
- Administration → Bar setup: categories, products, tables.
- Public pay link `/bar/pay/{TAB-…}?token=…` using the existing Pay panel.
- Receipts and payment result page understand bar bills.

## [0.4.0] — 2026-10-01 — M2c Check-in / stays / check-out

### Added
- Reservation page: Check in (room choice, ID capture), Extend stay, Move room, Check out
  (bill preview, late-fee waiver, take payment, manager override), link to the final bill.
- Guest bill card: services, other items, reductions, voids; running balance.
- Front desk: Check in / Check out buttons on today's arrivals and departures.
- Hotel services price list (Settings), printable/emailable final bill page,
  "Require an ID before check-in" policy.

## [0.3.0] — 2026-09-30 — M2b Payments

### Added
- Pay panel (deposit or full amount, gateway choice, processing fee shown) on the booking
  confirmation page and in "My reservations"; `/pay/callback` result page with polling.
- Staff: Payments (search, filters, needs-attention), payment detail with gateway re-check,
  printable/emailable receipts, refunds queue with second-approver flow, daily cash-up.
- Record cash/POS/transfer payments and request refunds from the reservation page.
- Gateway fee schedule editor; "payer covers fees" and refund approval limit in policies.

## [0.2.0] — 2026-09-29 — M2a Hotel core

### Added
- Public site: home search, rooms list and room detail pages, availability results with
  live price quote, booking form (guest or signed-in), confirmation page with hold countdown.
- Customer account: "My reservations" with cancellation.
- Staff: Front desk (arrivals, departures, in-house, occupancy, room board with status
  changes), Reservations (filters, new booking wizard with guest picker, detail, notes,
  cancel), Rooms (rooms, blocks, room types, rates, amenities, photos), Guests (search,
  profile, ID documents upload/verify/download, stay history), Property & policies settings.
- BFF passes file uploads and downloads through unchanged.
- Money/date helpers (`formatNaira`, hotel-timezone dates). 40 tests.

## [0.1.0] — 2026-09-28 — M1 Foundation

### Added
- Next.js 16 app (App Router, TypeScript strict, Tailwind v4 design tokens, light/dark).
- Backend-for-frontend proxy with httpOnly session cookie, token stripping and CSRF checks.
- Route guard (`proxy.ts`) and server-side session checks in layouts.
- Customer: register, sign in, forgot/reset password, account page with password change.
- Staff: sign in with email-code 2FA step, forced temporary-password change, portal shell
  with permission-filtered navigation, dashboard, users (search, create, edit, roles,
  suspend/reactivate, temporary password), roles & permission matrix, payment gateway
  settings (test/live keys, mode, enable/default, connection test), audit log browser.
- Accessible UI primitives and standard loading/empty/error/unauthorised states.
- Vitest suite (35 tests) and GitHub Actions CI (lint, type-check, test, build, audit).
