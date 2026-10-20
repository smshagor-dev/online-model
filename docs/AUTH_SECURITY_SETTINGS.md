# Registration and sign-in security

The settings are editable in **Administration → Workspace settings**.

- **New account registration:** enable or disable new registrations. The backend enforces this independently of the Next.js UI.
- **Email verification for new registrations:** when enabled, new accounts are left unverified and are not given a bearer token. A signed email link expires after 60 minutes. The user must open the link before signing in. The **Resend verification email** control can issue a fresh signed link.
- **Unfamiliar IP login verification:** when enabled, the first successful login establishes a trusted address. On a subsequent login from another IPv4 address or IPv6 /64 subnet, the server verifies password and existing authenticator 2FA first, then sends a six-digit security code by email. A valid code from the same IP is required before any bearer token is issued. Codes expire in 10 minutes, can be used only once, and are invalidated after five incorrect attempts. Email challenges are limited to five per user per hour and standard authentication rate limits still apply. IP fingerprints are stored using HMAC rather than raw IP addresses. Password changes invalidate outstanding challenges.

Both features initially default to **disabled** to preserve existing account behavior until the administrator has confirmed that outgoing mail works. Enable the switches after configuring SMTP and successfully sending a test message from **SMTP settings**.

Email verification works for supported addresses including Gmail, using the configured mail service; it is not Sign in with Google/OAuth, and it does not restrict registrations to @gmail.com addresses.

## Deployment

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
```

Important: If Next.js proxies authentication requests, Laravel must see the **real client IP through a trusted reverse-proxy chain**. Configure `TRUST_PROXY_HEADERS` only for controlled ingress and `TRUSTED_PROXIES` with the actual proxy addresses; never trust arbitrary internet-supplied `X-Forwarded-For`. If every Laravel request appears to originate at the Next.js server, the unfamiliar-IP feature cannot detect client network changes. IP changes alone are an imperfect risk signal; VPNs, mobile networks, and corporate proxies may trigger additional challenges. First-login trust establishment means existing users without IP history are not challenged until a new IP is seen after that baseline.

Run feature tests:

```bash
php artisan test --filter=AuthenticationSecuritySettingsTest
```

Browser login and production SMTP delivery also need real deployment verification.
