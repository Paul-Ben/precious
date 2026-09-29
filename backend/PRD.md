# Product Requirements (summary)

The complete product definition is the master spec: [docs/spec/master-spec.md](docs/spec/master-spec.md).
This page summarises it and tracks delivery.

## Vision

One hospitality operating system for a Nigerian hotel with a bar: rooms, reservations,
guests, check-in/out, services, bar orders, staff, shifts, billing, payments, finance,
notifications, reports and audit — with a premium public website and fast, touch-friendly
staff tools. Hotel and bar are domains of one platform sharing identity, billing and payments;
bar and service charges can be posted to a guest's room and settled at checkout.

## Users

Super Administrator · Administrator · Hotel Manager · Receptionist · Accountant · Waiter ·
Service Agent · Bartender · Inventory Manager · Auditor · Customer/Guest. Users may hold
several roles; permissions are the real authorisation mechanism.

## Milestones

| | Milestone | Scope | Status |
|---|---|---|---|
| M0 | Planning | Docs, assumptions, repo structure | ✅ Done |
| M1 | Foundation | Laravel + Next.js + PostgreSQL, API standards, auth, email 2FA, RBAC, audit, gateway key settings, CI | ✅ Built — awaiting review |
| M2 | Hotel core | Property, rooms, guests, availability engine, reservations, Paystack/Flutterwave payments, receipts, check-in/out | Next |
| M3 | Billing & services | Guest ledger, services, consolidated bill, partial payments | |
| M4 | Bar | Tables, products, waiter tablet UI, bartender queue (Reverb), bill email + pay link, charge to room | |
| M5 | Staff | Employees, shifts, attendance, notifications | |
| M6 | Finance | Revenue, expenses, reports, exports, dashboards | |
| M7 | Production | Queues/scheduler hardening, monitoring, backups, Railway | |
| M8 | Public website | Premium, futuristic site built on the reservation API | |

## Success criteria

See master spec §82. The end-to-end acceptance scenario (§81) must pass as an automated
test before production.
