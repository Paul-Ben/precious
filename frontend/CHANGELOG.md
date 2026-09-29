# Changelog

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
