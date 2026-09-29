# Deployment

Production deployment is milestone **M7**. This is the agreed target so earlier work stays
compatible with it.

## Environments

| Env | Database | Mail | Gateways |
|---|---|---|---|
| local | `precious` on your machine | `log` | test keys (entered in UI) |
| testing (CI) | `precious_testing` (GitHub Actions service) | `array` | faked |
| staging | Railway PostgreSQL (separate) | Resend (test domain) | test keys |
| production | Railway PostgreSQL | Resend (verified domain) | live keys |

## Railway topology (one GitHub repo, several services)

The code lives in one repository (`precious`) with `backend/` and `frontend/` folders.
Every Railway service connects to that same repo and is told which folder it builds from
(**Settings → Source → Root Directory**) and which changes trigger it (**Watch Paths**):

| Service | Root directory | Watch paths | Start command |
|---|---|---|---|
| api | `/backend` | `/backend/**` | web server (FrankenPHP / `php artisan serve`) — health check `/api/v1/health` |
| worker | `/backend` | `/backend/**` | `php artisan queue:work --tries=3 --max-time=3600` |
| scheduler | `/backend` | `/backend/**` | `php artisan schedule:work` |
| reverb (from M4) | `/backend` | `/backend/**` | `php artisan reverb:start` |
| web | `/frontend` | `/frontend/**` | `node .next/standalone/server.js` |
| PostgreSQL, Redis | Railway plugins | – | – |

With watch paths set, a frontend-only commit redeploys only `web`, and a backend-only
commit leaves `web` alone.

Pre-deploy command for `api`:

```bash
php artisan migrate --force && php artisan db:seed --class=Database\\Seeders\\CoreSeeder --force && php artisan optimize
```

## Required production variables

`APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `FRONTEND_URL`,
`DB_*` (from Railway), `REDIS_*`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`,
`MAIL_MAILER=resend`, `RESEND_API_KEY`, `MAIL_FROM_ADDRESS`, `R2_*`,
`SUPER_ADMIN_EMAIL` (first deploy only), `LOG_CHANNEL=stderr`.

Payment gateway keys are **not** environment variables — enter them in
Staff → Settings → Payment gateways.

## CI/CD

Two workflows at the repository root, each limited to its folder:

* `.github/workflows/backend.yml` (changes in `backend/**`): Composer install → Pint →
  migrate up/down/up → tests → `composer audit`.
* `.github/workflows/frontend.yml` (changes in `frontend/**`): `npm ci` → lint → type check →
  tests → build → `npm audit`.

Railway auto-deploys `main` only after the checks pass ("Wait for CI" enabled on each service).
