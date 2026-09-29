# Database

PostgreSQL 16+. The logical blueprint for all modules is in the master spec §41–43 and §71.
This file tracks what exists.

## Conventions

* UUID (v7, time-ordered) primary keys for business entities (`users`, `audit_logs`,
  `two_factor_challenges`); bigint for reference tables (roles, permissions, gateway settings).
* `snake_case` plural table names; FKs named `<entity>_id` and always indexed.
* Status columns are `varchar` + `CHECK` constraints (easy to extend, still enforced).
* Money (from M2): `numeric(14,2)` + `currency char(3) default 'NGN'`. Never floats.
* Financial and audit tables are append-only; corrections are new rows.
* Case-insensitive uniqueness where users type identifiers (`LOWER(email)` unique index).

## Tables (M1)

| Table | Purpose | Notable constraints |
|---|---|---|
| `users` | Customers and staff | `type IN (customer, staff)`, `status IN (active, suspended)`, unique `LOWER(email)`, soft deletes |
| `password_reset_tokens` | Laravel password broker | |
| `sessions` | Framework table (API is stateless) | |
| `cache`, `cache_locks` | Database cache store | |
| `jobs`, `job_batches`, `failed_jobs` | Queue | |
| `personal_access_tokens` | Sanctum tokens (UUID morph) | + `two_factor_confirmed`, `ip_address`, `user_agent`, indexed `expires_at` |
| `permissions` | Permission catalog | unique `(name, guard_name)`, + `group`, `description` |
| `roles` | Configurable roles | unique `(name, guard_name)`, + `description`, `is_system` |
| `model_has_roles` / `model_has_permissions` / `role_has_permissions` | Spatie pivots (UUID `model_id`) | |
| `audit_logs` | Append-only audit trail | JSONB old/new/metadata; **trigger blocks UPDATE/DELETE** |
| `two_factor_challenges` | Email OTP challenges | hashed code, attempts, expiry, consumed_at |
| `payment_gateway_settings` | Paystack/Flutterwave config | `gateway` unique, `mode IN (test, live)`, **partial unique index: one default**, `credentials` encrypted with `APP_KEY` |

## Tables (M2a — hotel core)

| Table | Purpose | Notable constraints |
|---|---|---|
| `document_sequences` | Gapless numbers per prefix + year (`RES-2026-00001`, later `RCP-…`) | unique `(prefix, year)`, row-locked increment |
| `properties` | The hotel (single row for now) | unique `slug` |
| `settings` | Per-property policy overrides (`config/hotel.php` holds defaults) | unique `(property_id, key)`, JSONB value |
| `departments` | Front desk, housekeeping, bar, … | unique `(property_id, code)` |
| `amenities`, `amenity_room_type` | Amenity catalogue + pivot | |
| `room_types` | Sellable categories | `base_rate ≥ 0`, `max_adults ≥ 1`, occupancy CHECKs, soft deletes |
| `room_type_images` | Photos (public disk / R2 public) | ordered by `position` |
| `rooms` | Physical rooms | unique `(property_id, number)`, `status IN (…)` |
| `room_blocks` | Maintenance/owner/other blocks | `ends_on > starts_on` (exclusive end) |
| `guests` | Guest profiles (optionally linked to a customer user) | UUID, unique `user_id`, `LOWER(email)` index, soft deletes |
| `guest_documents` | ID documents (private disk / R2 private) | UUID, number encrypted with `APP_KEY` |
| `reservations` | Bookings | UUID, unique `number`, status/source/payment-status CHECKs, money `numeric(14,2)`, `pricing_snapshot` JSONB, hashed `lookup_token` |
| `reservation_rooms` | One row per booked room | **`EXCLUDE USING gist (room_id WITH =, daterange(check_in, check_out, '[)') WITH &&) WHERE (is_active)`** |
| `reservation_guests` | Additional guests on a booking | |

**No double booking.** Allocation locks candidate rooms (`SELECT … FOR UPDATE`) and the
exclusion constraint `reservation_rooms_no_overlap` is the final guard: if two requests race,
PostgreSQL rejects the second insert (SQLSTATE `23P01`), the service tries the next free room,
and returns `ROOM_UNAVAILABLE` when none are left. Cancelling, expiring or no-show sets
`is_active = false`, which releases the room.

## Tables (M2b — payments)

| Table | Purpose | Notable constraints |
|---|---|---|
| `payments` | Every online attempt and desk payment (polymorphic `payable`: reservations now, bar bills in M4) | UUID, unique `reference`, method/status/purpose CHECKs, **`charged_amount = amount + customer_fee`**, `refunded_amount ≤ amount`, gateway set iff method = GATEWAY |
| `receipts` | One per successful payment, content frozen as JSONB | unique `number` (`RCP-2026-000001`), unique `payment_id` |
| `refunds` | Refund workflow | status CHECK, `amount > 0`, **second approver ≠ requester** when required |
| `webhook_events` | Raw gateway deliveries | unique `(gateway, event_key)` — each delivery processed once |

Money is credited to a reservation only after server-side verification with the gateway, under a
row lock on the payment and the reservation, and only once (final-status check).

## Tables (M2c — stays & bill)

| Table | Purpose | Notable constraints |
|---|---|---|
| `services` | Price list of extras | `price ≥ 0`, unique `(property_id, name)`, soft deletes |
| `stays` | A guest occupying a room (a move closes one stay and opens another) | status CHECK, **one OPEN stay per room** (partial unique index), ID number encrypted |
| `reservation_charges` | Bill items: services, extra nights, late check-out, adjustments | category/status CHECKs, `total = subtotal + service_charge + vat`, only ADJUSTMENT may be negative |
| `folio_statements` | Final bill issued at check-out, frozen JSONB | unique `number` (`FOL-2026-00001`), one per reservation |
| `reservations` (+) | `charges_total`, `checked_in_at`, `checked_out_at`, `checked_out_by`, `balance_at_checkout` | |

## Tables (M3 — bar / POS)

| Table | Purpose | Notable constraints |
|---|---|---|
| `bar_categories`, `bar_products` | Menu (prices before tax) | unique names per property; `price ≥ 0`; products soft-deleted |
| `bar_tables` | Tables | status CHECK, unique name |
| `bar_tabs` | One visit's running bill | `TAB-YYYY-NNNNN`; status/settlement CHECKs; `total = subtotal − discount + service_charge + vat`, `discount ≤ subtotal`; encrypted pay-link token |
| `bar_orders`, `bar_order_items` | Rounds sent to the bar, with price snapshots | `ORD-YYYY-NNNNNN`; status CHECK; `line_total = unit_price × quantity` |
| `reservation_charges` (+) | `bar_tab_id`; category `BAR` added | |

Payments for bar bills reuse `payments`/`receipts` with `payable_type = 'bar_tab'`.

## Migration order

```text
0001_01_01_000000  users, password_reset_tokens, sessions
0001_01_01_000001  cache
0001_01_01_000002  jobs
2026_09_28_000100  personal_access_tokens
2026_09_28_000200  permission tables
2026_09_28_000300  audit_logs (+ immutability trigger)
2026_09_28_000400  two_factor_challenges
2026_09_28_000500  payment_gateway_settings
2026_09_29_000100  btree_gist extension, document_sequences
2026_09_29_000200  properties, settings, departments
2026_09_29_000300  amenities, room_types, amenity_room_type, room_type_images
2026_09_29_000400  rooms, room_blocks
2026_09_29_000500  guests, guest_documents
2026_09_29_000600  reservations, reservation_rooms (+ exclusion constraint), reservation_guests
2026_09_30_000100  payments, receipts, refunds, webhook_events
2026_10_01_000100  services, stays, reservation_charges, folio_statements (+ grants checkouts.override_balance to Hotel Manager)
2026_10_02_000100  bar_categories, bar_products, bar_tables, bar_tabs, bar_orders, bar_order_items (+ BAR charge category)
```

Next (M4): Reverb realtime; (M5+) staff, inventory, finance. The `btree_gist` extension needs a superuser/owner the first time (`postgres` locally;
Railway's default role has the rights).

## Seeders

| Seeder | Idempotent | What |
|---|---|---|
| `CoreSeeder` → `PermissionSeeder` | ✓ | Syncs catalog; deletes permissions removed from code |
| `CoreSeeder` → `RoleSeeder` | ✓ | Creates the 11 system roles; never overwrites edited permissions (except the two admin roles, which always get everything) |
| `CoreSeeder` → `PaymentGatewaySeeder` | ✓ | Disabled Paystack + Flutterwave rows |
| `CoreSeeder` → `PropertySeeder` | ✓ | The hotel, 8 departments, 15 amenities |
| `DemoHotelSeeder` | ✓ | **Local only.** Sample room types, rates and 15 rooms |
| `SuperAdminSeeder` | ✓ | First Super Administrator from `SUPER_ADMIN_*` env (forced password change + 2FA) |

Run `php artisan db:seed --class=Database\\Seeders\\CoreSeeder --force` after every deploy
that adds permissions.

## `APP_KEY` warning

Gateway credentials are encrypted with `APP_KEY`. Rotating `APP_KEY` without re-encrypting
makes them unreadable (use `APP_PREVIOUS_KEYS` during rotation, then re-save the keys).
