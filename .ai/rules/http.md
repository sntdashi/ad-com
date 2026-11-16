---
paths:
    - 'app/Http/**'
---

# Http

## Require TOTP for operational accounts

Operator, admin, supervisor, technician, and warehouse accounts must have confirmed Fortify TOTP before entering operational routes. Customer accounts are exempt. Google OAuth users with enabled TOTP must pass the Fortify challenge, and internal users may not disable TOTP themselves.
