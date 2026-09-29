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
| 409 | `ROOM_UNAVAILABLE` | Not enough free rooms of a type for the dates (someone booked first) |
| 409 | `ROOM_HAS_BOOKINGS` (+`reservations`) | Blocking a room that has bookings in that period |
| 422 | `INVALID_DATES`, `INVALID_ROOMS`, `OCCUPANCY_EXCEEDED` | Booking request breaks a policy (past dates, too many nights/rooms, too many guests) |
| 422 | `INVALID_STATUS` | Action not allowed in the reservation's current status |
| 422 | `ROOM_TYPE_IN_USE`, `ROOM_IN_USE`, `GUEST_HAS_RESERVATIONS` | Delete refused because records depend on it |
| 409 | `NOT_PAYABLE` | Reservation cannot take money (expired hold, cancelled, …) |
| 422 | `NO_PAYMENT_GATEWAY`, `GATEWAY_DISABLED`, `INVALID_PAYMENT_OPTION`, `EMAIL_REQUIRED` | Online payment cannot start |
| 422 | `INVALID_AMOUNT` (+`balance` / `refundable`), `NOT_REFUNDABLE`, `INVALID_METHOD` | Desk payment / refund amount rules |
| 403 | `SELF_APPROVAL` | A refund's second approver must be someone else (P14) |
| 401 | `INVALID_SIGNATURE` | Webhook signature check failed |
| 502 | `GATEWAY_UNAVAILABLE` | Paystack/Flutterwave could not be reached |
| 409 | `DEPOSIT_REQUIRED`, `ROOM_NOT_READY`, `NOT_IN_HOUSE`, `OVERSTAY`, `BALANCE_DUE` (+`balance`) | Check-in / bill / check-out rules |
| 422 | `NOT_ARRIVAL_DAY`, `ID_REQUIRED`, `INVALID_ROOM`, `INVALID_STAY`, `DUPLICATE_SERVICE` | Check-in / bill validation |
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

## Endpoints (M2a — hotel core)

Money is returned as decimal strings (`"45000.00"`), dates as `YYYY-MM-DD` in hotel time
(Africa/Lagos). `check_out` is the departure day (exclusive).

### Public — `/public` (no login; `throttle:public` 120/min)

| Method | Path | Notes |
|---|---|---|
| GET | `/public/property` | Hotel details + public policies |
| GET | `/public/room-types` | Active types with images and amenities |
| GET | `/public/room-types/{slug}` | |
| GET | `/public/availability?check_in&check_out&adults&children` | Free rooms per type + nightly rate |
| POST | `/public/quote` | `{check_in, check_out, rooms:[{room_type_id, quantity}]}` → subtotal, service charge, VAT, total, deposit |
| POST | `/public/reservations` | `throttle:booking` 10/min. Guest details + `accept_terms`. Creates `PENDING_PAYMENT` with a 30-min hold. Returns `lookup_token` once (links to the account if signed in) |
| GET | `/public/reservations/{number}?token=` | `throttle:booking-lookup`. Guest looks up their own booking |

### Customer — `/me` (signed-in customers)

| GET | `/me/reservations` · `/me/reservations/{uuid}` | Own bookings only |
|---|---|---|
| POST | `/me/reservations/{uuid}/cancel` | `{reason?}` |

### Staff

| Method | Path | Permission |
|---|---|---|
| GET / PATCH | `/property` | `settings.view` / `settings.update` — `{property:{…}, policies:{…}}` |
| GET, POST, PATCH, DELETE | `/amenities[/{id}]` | `rooms.view` / `rooms.update` |
| GET, POST, PATCH, DELETE | `/room-types[/{id}]` | `rooms.view` / `rooms.create` / `rooms.update` |
| POST, PUT order, DELETE | `/room-types/{id}/images[…]` | `rooms.update` (multipart `image`, ≥ 600×400, ≤ 5 MB) |
| GET | `/rooms`, `/rooms/board?date=` | `rooms.view` |
| POST, PATCH, DELETE | `/rooms[/{id}]` | `rooms.create` / `rooms.update` |
| PATCH | `/rooms/{id}/status` | `rooms.manage_status` |
| POST, DELETE | `/rooms/{id}/blocks[/{block}]` | `rooms.manage_status` — `{starts_on, ends_on, reason, note?}` |
| GET, POST, PATCH, DELETE | `/guests[/{uuid}]` | `guests.view` / `guests.create` / `guests.update` |
| GET | `/guests/{uuid}/reservations` | `guests.view` |
| GET, POST | `/guests/{uuid}/documents` | `guests.documents.view` (multipart `file`, `type`, `number?`; ≤ 8 MB) |
| GET | `/guests/{uuid}/documents/{uuid}/download` | `guests.documents.view` — streamed, audited |
| POST, DELETE | `/guests/{uuid}/documents/{uuid}[/verify]` | `guests.documents.view` |
| GET | `/reservations?status&from&to&search&source&page` | `reservations.view` |
| GET | `/reservations/availability` | `reservations.view` or `.create` |
| POST | `/reservations` | `reservations.create` — `source`, `guest_id` or `guest{}`, `hold_minutes?` |
| GET, PATCH | `/reservations/{uuid}` | `reservations.view` / `reservations.update` (notes, special requests) |
| POST | `/reservations/{uuid}/cancel` | `reservations.cancel` |
| GET | `/front-desk/summary?date=` | `reservations.view` — arrivals, departures, in-house, occupancy |

## Endpoints (M2b — payments)

### Guest / customer

| Method | Path | Notes |
|---|---|---|
| GET | `/public/reservations/{number}/payment-options?token=` | Deposit / full options, processing fee per enabled gateway |
| POST | `/public/reservations/{number}/payments` | `{token, option: deposit\|balance, gateway?}` → `{reference, authorization_url, amount, customer_fee, charged_amount}`. `throttle:payments` |
| GET / POST | `/me/reservations/{uuid}/payment-options` · `/me/reservations/{uuid}/payments` | Same for signed-in customers (own bookings only) |
| POST | `/public/payments/verify` | `{reference}` — called by the `/pay/callback` page; verifies with the gateway and settles |
| POST | `/webhooks/payments/{paystack\|flutterwave}` | Paystack: `x-paystack-signature` (HMAC-SHA512 of the body with the secret key). Flutterwave: `verif-hash` = secret hash. Payload is never trusted — the payment is re-verified via the gateway API |

The gateway redirects to `FRONTEND_URL/pay/callback` (Paystack adds `reference`/`trxref`, Flutterwave `tx_ref`).

### Staff

| Method | Path | Permission |
|---|---|---|
| GET | `/payments?status&method&from&to&search&attention&page` | `payments.view` |
| GET | `/payments/summary?date=` | `payments.view` — daily cash-up by method, refunds, cash in drawer |
| GET | `/payments/{uuid}` | `payments.view` |
| POST | `/payments/{uuid}/verify` | `payments.view` — re-check a pending online payment |
| POST | `/payments/{uuid}/resolve` | `payments.refund` — `{note}` clears "needs attention" |
| GET | `/reservations/{uuid}/payments` | `payments.view` or `reservations.view` |
| POST | `/reservations/{uuid}/payments` | `payments.create` — `{method: CASH\|POS\|BANK_TRANSFER, amount, external_reference?, note?, send_receipt?}` |
| GET | `/receipts/{RCP-YYYY-NNNNNN}` · POST `/receipts/{number}/email` | `payments.view` / `reservations.view` |
| GET | `/refunds?status` | `payments.view` |
| POST | `/payments/{uuid}/refunds` | `payments.refund` — `{amount, reason}` |
| POST | `/refunds/{uuid}/approve` · `/reject` (`note` required) · `/complete` (`method`, `external_reference`) | `payments.refund` |
| PATCH | `/settings/payment-gateways/{gateway}` | now also accepts `fees: {percent, flat, flat_waived_below, cap}` (null = published default) |

## Endpoints (M2c — check-in, stays, check-out)

| Method | Path | Permission |
|---|---|---|
| GET | `/stays` | `reservations.view` — in-house guests |
| GET | `/reservations/{uuid}/check-in` | `checkins.create` — blockers, ID requirement, room choices |
| POST | `/reservations/{uuid}/check-in` | `checkins.create` — `{rooms?: [{reservation_room_id, room_id}], id_type?, id_number?, notes?}` |
| POST | `/reservations/{uuid}/extend` | `reservations.update` — `{check_out}`; extra nights become bill items |
| POST | `/stays/{uuid}/move` | `checkins.create` — `{room_id, reason}` |
| GET | `/reservations/{uuid}/check-out` | `checkouts.create` — bill, late fee, balance, `can_override_balance` |
| POST | `/reservations/{uuid}/check-out` | `checkouts.create` — `{apply_late_fee?, waive_reason?, override_balance?, override_reason?}` → `{reservation, statement_number}` |
| GET · POST email | `/folios/{FOL-YYYY-NNNNN}` | `reservations.view` / `payments.view` |
| POST | `/reservations/{uuid}/charges` | `services.charge` or `bills.update` (`ADJUSTMENT` also needs `discounts.approve`) — `{service_id, quantity}` or `{category: OTHER, description, unit_price, quantity?, charges_vat?, charges_service_charge?}` or `{category: ADJUSTMENT, amount, reason}` |
| POST | `/charges/{uuid}/void` | `bills.update` — `{reason}` |
| GET / POST / PATCH / DELETE | `/services[/{id}]` | `services.view` / `services.manage` |

Reservations now return `charges_total`, `grand_total` (= `total` + `charges_total`), `charges`,
and for staff `stays`, `checked_in_at`, `checked_out_at`, `balance_at_checkout`, `folio_number`.
`balance` includes charges.
