# Hotel & Bar Platform — Backend (Laravel API)

The authoritative business-logic layer for the Hotel & Bar Unified Management Platform.
This is the `backend/` folder of the `precious` monorepo; the Next.js app is in `../frontend`.

**Status:** Milestone M2 complete — rooms, guests, reservations (M2a), online & desk payments,
receipts, refunds (M2b), check-in, guest bill, services and check-out (M2c), on top of M1
(auth, 2FA, RBAC, audit), and the bar / POS (M3). Next: M4 live updates (Reverb) and reports.

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
php artisan db:seed --class=DemoHotelSeeder   # optional sample rooms, services, bar menu and tables (local only)
php artisan storage:link                       # serves room photos from storage/app/public
```

Room photos go to `MEDIA_DISK` (default `public`) and ID documents to `DOCUMENTS_DISK`
(default `local`, private). In staging/production set `MEDIA_DISK=r2_public` and
`DOCUMENTS_DISK=r2_private`.

Serve it with Herd. Because the app is nested in `precious\backend`, link it to a clean domain:

```powershell
herd link precious-api        # -> http://precious-api.test
curl http://precious-api.test/api/v1/health
```

Background work (run in separate terminals while developing):

```powershell
php artisan queue:listen --tries=1
php artisan schedule:work     # expires unpaid holds, marks no-shows, reconciles payments
```

### Trying online payments locally

1. Staff → Settings → Payment gateways: paste your **test** keys, enable, make one default.
2. Book a room on the website and press **Pay**. You are sent to the gateway's test checkout -
   use a test card from the gateway's docs (Paystack: paystack.com/docs/payments/test-payments,
   Flutterwave: developer.flutterwave.com → Testing helpers).
3. Returning to `/pay/callback` verifies the payment even without webhooks.
4. Webhooks need a public URL. For local testing run a tunnel, e.g.
   `cloudflared tunnel --url http://precious-api.test`, and paste
   `<tunnel-url>/api/v1/webhooks/payments/paystack` into the gateway dashboard. Optional - the
   `payments:reconcile` job also settles payments every 10 minutes.

Do **not** turn on "pass fees to customer" inside the Paystack/Flutterwave dashboards - the
platform already adds the processing fee (Property & policies → *Payer covers online payment fees*).

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
