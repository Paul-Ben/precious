# Identity & Access

## Login state machine

```text
             POST /auth/login | /auth/staff/login
                        │
          credentials ok? ──no──► 422 (same message for unknown email / wrong password)
                        │yes
          account active? ──no──► 403 ACCOUNT_SUSPENDED
                        │yes
         requires 2FA? ──no──► token (two_factor_confirmed = false)
                        │yes
            create challenge, email code
                        │
          POST /auth/two-factor/verify (challenge_id, code)
             ├─ wrong code  → 422 TWO_FACTOR_INVALID (remaining_attempts)
             ├─ 5th wrong   → 422 TWO_FACTOR_LOCKED
             ├─ expired/used→ 422 TWO_FACTOR_EXPIRED
             └─ ok          → token (two_factor_confirmed = true)

   token + must_change_password → only /auth/me, /auth/logout, /auth/password/change
```

## Role grant rules

| Actor | Can grant |
|---|---|
| Super Administrator | Any role, including Super Administrator |
| Anyone with `roles.assign` | Roles whose permissions are a subset of their own; never Super Administrator |
| Anyone | Never their own roles |

The same subset rule applies to editing a role's permissions (`roles.update`), and nobody
except a Super Administrator can edit a role they hold.
