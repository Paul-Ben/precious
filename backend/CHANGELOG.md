# Changelog

## [0.1.0] — 2026-09-28 — M0 + M1 Foundation

### Added
- Laravel 13 API skeleton on PostgreSQL with `/api/v1` prefix, standard JSON envelope,
  unified exception rendering, request ids, security headers, health endpoint.
- Customer registration/login, staff login, logout, forgot/reset password, change password.
- Temporary passwords for staff with forced change on first login.
- Email OTP two-factor authentication, mandatory for Super Administrator and Administrator.
- RBAC with 59 code-defined permissions, 11 seeded system roles, multiple roles per user,
  role CRUD, permission sync, user management (create staff, roles, suspend, reactivate,
  temporary password).
- Anti-privilege-escalation rules.
- Append-only audit log with DB trigger and secret redaction.
- Payment gateway settings for Paystack and Flutterwave (encrypted test/live keys, mode,
  enable/default, connection test).
- Resend mail and Cloudflare R2 disk configuration.
- 77 PHPUnit tests; GitHub Actions CI.
- Project documentation and assumptions register.
