# API (v1)

Base URL: `/api/v1`. JSON only. Authenticated routes need `Authorization: Bearer <token>`
(the Next.js BFF adds it; browsers never hold tokens).

## Envelope

```json
{ "success": true,  "message": "Reservation created successfully.", "data": {}, "meta": {} }
{ "success": false, "message": "Validation failed.", "code": "VALIDATION_FAILED", "errors": { "email": ["…"] } }
{ "success": false, "message": "The selected room is no longer available.", "code": "ROOM_UNAVAILABLE" }
```

Paginated lists put `current_page, per_page, total, last_page` in `meta`.
Every response carries an `X-Request-Id` header (send your own to correlate).

## Error codes

| HTTP | code | Meaning |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Missing/expired/revoked token |
| 403 | `FORBIDDEN` | Missing permission |
| 403 | `ACCOUNT_SUSPENDED` | Account suspended |
| 403 | `PASSWORD_CHANGE_REQUIRED` | Temporary password must be changed first |
| 403 | `TWO_FACTOR_REQUIRED` | Token was issued without 2FA for a user who needs it |
| 403 | `PRIVILEGE_ESCALATION`, `SELF_ROLE_CHANGE`, `SELF_SUSPEND` | Anti-escalation rules |
| 404 | `NOT_FOUND` | |
| 422 | `VALIDATION_FAILED` | Field errors in `errors` |
| 422 | `TWO_FACTOR_INVALID` (+`remaining_attempts`), `TWO_FACTOR_LOCKED`, `TWO_FACTOR_EXPIRED` | 2FA |
| 422 | `SYSTEM_ROLE`, `ROLE_IN_USE`, `UNKNOWN_ROLE`, `INVALID_ROLE`, `LAST_SUPER_ADMIN` | RBAC rules |
| 422 | `GATEWAY_INCOMPLETE`, `GATEWAY_DISABLED` | Payment gateway settings |
| 429 | `TOO_MANY_REQUESTS`, `TWO_FACTOR_COOLDOWN` (+`retry_after`) | Rate limits |
| 500 | `SERVER_ERROR` | Details only when `APP_DEBUG=true` |
| 503 | `SERVICE_DEGRADED` | Health check failing |

## Endpoints (M1)

### Public

| Method | Path | Notes |
|---|---|---|
| GET | `/health` | DB + cache checks; 503 when degraded |

### Auth — `/auth`

| Method | Path | Auth | Body / notes |
|---|---|---|---|
| POST | `/register` | – | `name, email, phone?, password, password_confirmation` → customer + token |
| POST | `/login` | – | Customers. `email, password` → token **or** 2FA challenge |
| POST | `/staff/login` | – | Staff. Same response shape |
| POST | `/two-factor/verify` | – | `challenge_id, code` → token |
| POST | `/two-factor/resend` | – | `challenge_id` (60 s cooldown) |
| POST | `/forgot-password` | – | `email` — always the same response |
| POST | `/reset-password` | – | `token, email, password, password_confirmation`; revokes all tokens |
| GET | `/me` | token | Current user incl. `roles` and effective `permissions` |
| POST | `/logout` | token | Revokes current token |
| POST | `/password/change` | token | `current_password, password, password_confirmation`; clears forced change, revokes other tokens |

Login response (no 2FA):

```json
{ "two_factor_required": false, "token": "hp_…", "token_type": "Bearer",
  "expires_at": "2026-10-05T12:00:00+01:00", "user": { "id": "…", "roles": ["Waiter"], "permissions": ["bar.orders.create"] } }
```

Login response (2FA):

```json
{ "two_factor_required": true,
  "two_factor": { "challenge_id": "0199…", "channel": "email", "destination": "ad**@example.com", "expires_at": "…" } }
```

### Staff area

All below require: token · active account · 2FA satisfied · password changed · staff user.

| Method | Path | Permission |
|---|---|---|
| GET | `/users?search=&type=&status=&role=&per_page=` | `users.view` |
| POST | `/users` (create staff: `name, email, phone?, roles[], two_factor_enabled?`) | `users.create` |
| GET | `/users/{id}` | `users.view` |
| PATCH | `/users/{id}` (`name, email, phone, two_factor_enabled`) | `users.update` |
| PUT | `/users/{id}/roles` (`roles[]`) | `roles.assign` |
| POST | `/users/{id}/suspend` (`reason?`) | `users.update` |
| POST | `/users/{id}/activate` | `users.update` |
| POST | `/users/{id}/temporary-password` | `users.update` |
| GET | `/permissions` (catalog grouped by module) | `roles.view` |
| GET | `/roles` | `roles.view` |
| POST | `/roles` (`name, description?, permissions[]`) | `roles.create` |
| GET | `/roles/{id}` | `roles.view` |
| PATCH | `/roles/{id}` (`name?, description?`) | `roles.update` |
| PUT | `/roles/{id}/permissions` (`permissions[]`) | `roles.update` |
| DELETE | `/roles/{id}` | `roles.delete` |
| GET | `/settings/payment-gateways` | `settings.payment_gateways.manage` |
| GET | `/settings/payment-gateways/{paystack\|flutterwave}` | same |
| PATCH | `/settings/payment-gateways/{gateway}` | same |
| POST | `/settings/payment-gateways/{gateway}/test` (`mode?`) | same |
| GET | `/audit-logs?action=&actor_id=&auditable_type=&auditable_id=&from=&to=` | `audit.view` |

#### Payment gateway settings body

```json
{
  "mode": "test",
  "is_enabled": true,
  "is_default": true,
  "credentials": {
    "test": { "public_key": "pk_test_…", "secret_key": "sk_test_…" },
    "live": { "public_key": "", "secret_key": "" }
  },
  "clear_credentials": ["live.secret_key"]
}
```

Blank credential values keep the stored value. Keys are prefix-checked per mode
(`pk_test_`/`pk_live_`, `FLWSECK_TEST-`/`FLWSECK-` …). Responses never contain secrets —
only `{ set: true, preview: "••••abcd" }`. A gateway cannot be enabled until all required
fields for its active mode are present.
