# Hotel & Bar Platform — Frontend (Next.js)

Public website, customer portal and staff portal for the Hotel & Bar Unified Management
Platform. All business logic lives in the Laravel API (`hotel-platform-backend`); this app
presents it. This is the `frontend/` folder of the `precious` monorepo; the API is in `../backend`.

**Status:** Milestones M2 + M3 — booking, online payment, front desk, guest bills, bar POS, payments and refunds.

| | |
|---|---|
| Framework | Next.js 16 (App Router, `proxy.ts`), React 19, TypeScript (strict) |
| Styling | Tailwind CSS v4 with design tokens (light/dark) in `src/app/globals.css` |
| Data | TanStack Query · React Hook Form · Zod |
| Tests | Vitest + Testing Library (jsdom) |

## Local setup

Prerequisites: Node 22 (see `.nvmrc`) and the backend running (e.g. `http://precious-api.test`).

```powershell
cd "C:\Users\O D G\Herd\precious\frontend"
npm install
copy .env.example .env.local     # set API_BASE_URL to your backend
npm run dev                       # http://localhost:3000
```

| Page | URL |
|---|---|
| Placeholder home | `/` |
| Customer sign-in / register | `/login`, `/register` |
| Staff sign-in | `/staff/login` |
| Staff portal | `/staff/dashboard`, `/staff/users`, `/staff/roles`, `/staff/settings/payment-gateways`, `/staff/audit-logs` |
| Customer portal | `/account` |

## Scripts

```powershell
npm run lint        # ESLint (next/core-web-vitals + TypeScript)
npm run typecheck   # route types + tsc --noEmit
npm test            # Vitest
npm run build       # production build (standalone output for Railway)
```

## How it talks to the API

The browser never calls Laravel directly and never sees a token.

```text
Browser ──fetch /api/bff/users──► Next.js route handler ──Bearer token──► Laravel /api/v1/users
                                   (token read from httpOnly cookie)
```

* `src/app/api/bff/[...path]/route.ts` — the proxy. Captures tokens from login responses into
  the `hp_session` httpOnly cookie, strips them from the JSON, blocks cross-site writes.
* `src/lib/api/client.ts` — typed browser client (`api.get/post/…`) that unwraps the envelope
  and throws `ApiError` (`status`, `code`, `errors`).
* `src/lib/server/api.ts` — `getCurrentUser()` for Server Component layouts.
* `src/proxy.ts` — redirects visitors without a session away from `/account`, `/staff/*`.

Permission checks in the UI (`can()`, `<RequirePermission>`) only hide what the user can't
use; the API enforces every permission itself.

## Structure

```text
src/
  app/                    routes (public, auth pages, /account, /staff/(portal)/…, /api/bff)
  components/ui/          accessible primitives: Button, Field, Input, Dialog, states…
  features/<module>/      screens + API hooks per module (auth, staff, users, roles, settings, audit)
  lib/api | auth | server | validation
  test/                   test setup & helpers
```

Every data screen handles loading, empty, error, unauthorised, validation and network
failure states (`components/ui/states.tsx`).

## Git

This folder is part of the `precious` monorepo — run git commands from the repository root
(see the root `README.md`). CI for this app is `.github/workflows/frontend.yml` at the root; it
runs only when files under `frontend/` change.
