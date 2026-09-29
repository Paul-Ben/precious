# System Requirements (summary)

Detailed requirements live in the master spec ([docs/spec/master-spec.md](docs/spec/master-spec.md)).
This file lists the requirements implemented so far with their verification.

## Functional — M1

| ID | Requirement | Verified by |
|---|---|---|
| F-AUTH-1 | Customers can register, sign in, sign out and reset their password | `RegistrationTest`, `LoginTest`, `PasswordTest` |
| F-AUTH-2 | Staff sign in on a separate portal with email + password | `LoginTest` |
| F-AUTH-3 | Staff created by an admin receive a temporary password and must change it on first login | `UserManagementTest`, `PasswordTest` |
| F-AUTH-4 | Administrators must complete email OTP 2FA | `TwoFactorTest` |
| F-RBAC-1 | Users can hold multiple roles; permissions combine | `PermissionEnforcementTest` |
| F-RBAC-2 | Roles are configurable; permissions are assigned to roles | `RoleManagementTest` |
| F-RBAC-3 | Removing a permission or role restricts access immediately | `PermissionEnforcementTest` |
| F-RBAC-4 | Every staff endpoint is permission-checked server-side | `PermissionEnforcementTest`, route definitions |
| F-PAY-0 | Admins configure Paystack/Flutterwave test and live keys; secrets are encrypted and never displayed | `PaymentGatewaySettingsTest` |
| F-AUD-1 | Authentication, role, permission, user and settings changes are audited | tests across suites, `AuditLogTest` |

## Non-functional — M1

| ID | Requirement | Implementation |
|---|---|---|
| N-API-1 | Versioned API under `/api/v1` with a standard envelope | `ApiResponse`, exception renderer |
| N-API-2 | No stack traces in production | `ApiConventionsTest` |
| N-SEC-1 | Rate limiting on auth and API | `AppServiceProvider` |
| N-SEC-2 | Tokens never reachable by browser JavaScript | Next.js BFF + httpOnly cookie |
| N-OPS-1 | Health endpoint for Railway | `GET /api/v1/health` |
| N-OPS-2 | Request correlation id | `AssignRequestId` |
| N-QA-1 | CI runs lint, migrations, tests and dependency audit on every PR | `.github/workflows/ci.yml` |
