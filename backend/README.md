# Hotel & Bar Platform — Backend (Laravel API)

The authoritative business-logic layer for the Hotel & Bar Unified Management Platform.
This is the `backend/` folder of the `precious` monorepo; the Next.js app is in `../frontend`.

**Status:** Milestone M1 (Foundation) — API standards, authentication, email 2FA, RBAC,
audit log, admin-configurable payment gateway keys, CI.

| | |
|---|---|
| Framework | Laravel 13 (PHP ≥ 8.3) |
| Database | PostgreSQL 16+ |
| Auth | Laravel Sanctum bearer tokens (held server-side by the Next.js BFF) |
| RBAC | spatie/laravel-permission — multiple roles per user, permission-driven |
| Mail | Resend (production), `log` driver locally |
| Storage | Cloudflare R2 (S3-compatible) — configured, used from M2 |
| API base | `/api/v1` |

## Documentation

| File | What's in it |
|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | System design, domains, request flow, auth model |
| [API.md](API.md) | Endpoint list, envelope, error codes |
| [DATABASE.md](DATABASE.md) | Tables, constraints, migration order |
| [SECURITY.md](SECURITY.md) | Threat model, controls, secrets handling |
| [TESTING.md](TESTING.md) | How to run tests, what is covered |
| [DEPLOYMENT.md](DEPLOYMENT.md) | Environments and Railway plan |
| [ASSUMPTIONS.md](ASSUMPTIONS.md) | Business rules not fixed by the spec — **please review** |
| [PRD.md](PRD.md) / [SRD.md](SRD.md) | Product & system requirements (summarised from the master spec) |
| [CHANGELOG.md](CHANGELOG.md) | Milestone history |
| [docs/spec/master-spec.md](docs/spec/master-spec.md) | The full master specification |

## Local setup (Windows + Laravel Herd + PostgreSQL)

```powershell
cd "C:\Users\O D G\Herd\precious\backend"

composer install
copy .env.example .env
php artisan key:generate
```

Edit `.env`:

```dotenv
DB_DATABASE=precious
DB_USERNAME=postgres
DB_PASSWORD=<your local postgres password>

SUPER_ADMIN_EMAIL=<your email>
SUPER_ADMIN_PASSWORD=<a temporary password, e.g. Temp-Passw0rd!>
```

Create the databases (once) and migrate + seed:

```powershell
psql -U postgres -c "CREATE DATABASE precious;"
psql -U postgres -c "CREATE DATABASE precious_testing;"

php artisan migrate --seed
```

Serve it with Herd. Because the app is nested in `precious\backend`, link it to a clean domain:

```powershell
herd link precious-api        # -> http://precious-api.test
curl http://precious-api.test/api/v1/health
```

Background work (run in separate terminals while developing):

```powershell
php artisan queue:listen --tries=1
php artisan schedule:work
```

`MAIL_MAILER=log` writes every email — including 2FA codes and temporary passwords — to
`storage/logs/laravel.log`, so you can sign in locally without an email provider.

### First sign-in

1. Open the frontend at `http://localhost:3000/staff/login`.
2. Sign in with `SUPER_ADMIN_EMAIL` / `SUPER_ADMIN_PASSWORD`.
3. Enter the 6-digit code from `storage/logs/laravel.log`.
4. Choose your own password (forced on first login).

## Everyday commands

```powershell
composer test          # php artisan test (uses the precious_testing database)
vendor/bin/pint        # format code (CI runs `pint --test`)
php artisan route:list --path=api
```

## Git

This folder is part of the `precious` monorepo — run git commands from the repository root
(see the root `README.md`). CI for this app is `.github/workflows/backend.yml` at the root; it
runs only when files under `backend/` change.

Branching: `main` (production), `develop`, `feature/*`, `fix/*`, `hotfix/*`. Protect `main` and
`develop` and require the **Backend CI** check to pass.
