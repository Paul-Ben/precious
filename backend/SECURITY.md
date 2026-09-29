# Security

## Implemented in M1

| Area | Control |
|---|---|
| Transport | HTTPS in production (Railway); HSTS header when served over TLS |
| Tokens | Sanctum bearer tokens, SHA-256 hashed at rest, expiring (customer 7 d / staff 12 h); stored by the Next.js BFF in an httpOnly SameSite=Lax cookie — never exposed to browser JS |
| CSRF | BFF rejects cross-origin state-changing requests (Origin / Sec-Fetch-Site) |
| CORS | Restricted to `FRONTEND_URL` |
| Passwords | bcrypt; policy ≥10 chars, mixed case, number, symbol; `uncompromised()` (HIBP k-anonymity) in production |
| Staff onboarding | Random temporary password, emailed directly (never queued), must be changed at first login |
| 2FA | Email OTP mandatory for Super Administrator / Administrator; hashed, single-use, 10 min, 5 attempts, resend cooldown |
| Enumeration | Same error for unknown email and wrong password; constant-time dummy hash check; forgot-password always returns the same message |
| Brute force | Rate limits on login (per email+IP and per IP), 2FA (per IP and per challenge), API (per user/IP) |
| Authorisation | Server-side `permission:` middleware on every staff route; frontend visibility is never trusted |
| Privilege escalation | Grant-only-what-you-hold; super-admin-only management of super admins; no self role changes; last super admin protected |
| Session revocation | Suspend → all tokens revoked; password change → other tokens revoked; reset → all revoked; promotion to a 2FA role → non-2FA tokens revoked |
| Secrets | Gateway keys encrypted with `APP_KEY`, write-only via API (masked previews), never in logs/audit; `.env` git-ignored |
| Audit | Append-only (model guard + PostgreSQL trigger); recursive redaction of password/token/secret/key/code fields; actor, IP, UA, request id |
| Errors | No stack traces when `APP_DEBUG=false`; consistent envelope |
| Headers | `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Cache-Control: no-store` on API responses |

## Planned

* **M2** webhook signature verification (Paystack HMAC-SHA512 / Flutterwave `verif-hash`),
  idempotency keys, payment reconciliation, signed URLs for private R2 files, upload MIME
  validation.
* **M7** narrow `trustProxies` to Railway's proxy range (currently `*`, which lets a client
  spoof `X-Forwarded-For` when calling the API directly and weakens per-IP limits),
  error monitoring, backup restore drills, dependency scanning alerts, security review.

## Secrets that must never be committed

`.env`, payment keys, `RESEND_API_KEY`, R2 keys, database passwords, `APP_KEY`.

## Reporting

Report vulnerabilities privately to the project owner; do not open public issues.
