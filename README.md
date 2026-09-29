# Precious — Hotel & Bar Unified Management Platform

One repository, two apps:

| Folder | What | Stack | Docs |
|---|---|---|---|
| [`backend/`](backend) | REST API — all business rules, auth, RBAC, payments, audit | Laravel 13 · PHP 8.4 · PostgreSQL | [backend/README.md](backend/README.md) |
| [`frontend/`](frontend) | Public website, guest portal, staff portal | Next.js 16 · TypeScript · Tailwind | [frontend/README.md](frontend/README.md) |

Project documentation (architecture, API, database, security, assumptions, the master
spec) lives in [`backend/`](backend) — start with
[ARCHITECTURE.md](backend/ARCHITECTURE.md) and [ASSUMPTIONS.md](backend/ASSUMPTIONS.md).

## Run locally (Windows + Herd)

```powershell
# API  -> http://precious-api.test (or php artisan serve --port=8000)
cd backend
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
herd link precious-api

# Web  -> http://localhost:3000
cd ..\frontend
npm install
copy .env.example .env.local
npm run dev
```

Full setup notes are in each app's README.

## CI

GitHub Actions at the repository root, each triggered only by changes to its own folder:

* `.github/workflows/backend.yml` — Pint, migrations up/down/up, PHPUnit on PostgreSQL, `composer audit`
* `.github/workflows/frontend.yml` — ESLint, type check, Vitest, production build, `npm audit`

## Deployment

Railway, one service per process, all connected to this repo with a root directory
(`/backend` or `/frontend`) and matching watch paths. See
[backend/DEPLOYMENT.md](backend/DEPLOYMENT.md).

## Branches

`main` (production) · `develop` · `feature/*` · `fix/*` · `hotfix/*`.
Protect `main` and `develop`; require **Backend CI** and **Frontend CI** to pass.
