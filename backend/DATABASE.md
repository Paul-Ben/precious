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
```

Next (M2): properties, departments, settings, room_types, amenities, rooms, room_images,
guests, guest_documents, reservations, reservation_rooms, reservation_guests,
room_allocations, stays — then payments. Reservation overlap protection will use a
PostgreSQL **exclusion constraint** (`btree_gist`, `tstzrange`) in addition to row locks.

## Seeders

| Seeder | Idempotent | What |
|---|---|---|
| `CoreSeeder` → `PermissionSeeder` | ✓ | Syncs catalog; deletes permissions removed from code |
| `CoreSeeder` → `RoleSeeder` | ✓ | Creates the 11 system roles; never overwrites edited permissions (except the two admin roles, which always get everything) |
| `CoreSeeder` → `PaymentGatewaySeeder` | ✓ | Disabled Paystack + Flutterwave rows |
| `SuperAdminSeeder` | ✓ | First Super Administrator from `SUPER_ADMIN_*` env (forced password change + 2FA) |

Run `php artisan db:seed --class=Database\\Seeders\\CoreSeeder --force` after every deploy
that adds permissions.

## `APP_KEY` warning

Gateway credentials are encrypted with `APP_KEY`. Rotating `APP_KEY` without re-encrypting
makes them unreadable (use `APP_PREVIOUS_KEYS` during rotation, then re-save the keys).
