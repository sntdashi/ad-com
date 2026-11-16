---
paths:
    - 'app/Http/Middleware/**'
---

# Middleware

## Keep mobile bearer authentication ahead of throttles

Mobile API v1 accepts explicit Sanctum bearer tokens only (no web-session fallback), scoped to customer/technician and matching current role. AuthenticateMobileToken implements AuthenticatesRequests so Laravel orders it before throttling and authorization; otherwise the shared ADI limiter may try to read a nonexistent API session. Internal mobile access requires confirmed Fortify TOTP and the auth:2fa token ability.
