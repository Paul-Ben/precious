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

## Conventions

* One behaviour per test, named as a sentence.
* Use `$this->staff('Role', …)`, `$this->customer()`, `$this->actingAsUser($user)` from
  `tests/TestCase.php` — they issue real Sanctum tokens.
* Fake external services: `Notification::fake()`, `Http::fake()`. No real network in tests.
* A module is not done until its API tests pass in CI.
