# Testing

```powershell
npm test            # run once
npm run test:watch  # watch mode
```

| File | Covers |
|---|---|
| `src/lib/auth/permissions.test.ts` | Multi-role permission checks, super admin, home routing, open-redirect protection |
| `src/lib/validation/auth.test.ts` | Password policy parity with the backend, registration, OTP, change password |
| `src/lib/api/client.test.ts` | Envelope unwrapping, pagination, validation errors, network failures, 403 |
| `src/components/ui/field.test.tsx` | Label / hint / error wiring for screen readers |
| `src/features/auth/login-form.test.tsx` | Validation, server errors, 2FA step, wrong-code attempts, forced password change, safe `?next=` |
| `src/app/api/bff/[...path]/route.test.ts` | Token → httpOnly cookie, bearer forwarding, CSRF rejection, path traversal, 401 clears session, API down |

End-to-end tests (Playwright) covering the full acceptance scenario are added once the
reservation and bar flows exist (M2–M4).
