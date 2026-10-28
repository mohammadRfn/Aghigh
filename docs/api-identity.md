# Identity API reference

Base path: `/api/v1`. Errors use RFC 9457 `application/problem+json`. Send `Accept: application/json`. This reference describes the included Laravel source, **not** a live API in the delivery workspace. Scramble discovers the module routes at `/docs/api` after installation; inspect that generated output before release.

| Method | Path | Success | Requirement |
| --- | --- | --- | --- |
| GET | /identity/ping | 200 | public; existing route preserved |
| POST | /auth/register | 202 | name, email, password, password_confirmation; local password policy |
| GET | /auth/email/verify/{id}/{hash} | 200 | complete signed URL from mail including expires/signature |
| POST | /auth/email/resend | 202 | email; enumeration-safe |
| POST | /auth/login | 200 | email/password; verified account; rate limit and lockout |
| POST | /auth/refresh | 200 | HttpOnly refresh cookie plus explicitly allowed Origin |
| POST | /auth/logout | 200 | Bearer JWT plus explicitly allowed Origin |
| POST | /auth/forgot-password | 202 | email; enumeration-safe |
| POST | /auth/reset-password | 200 | id/token from reset mail, password and password_confirmation |
| GET | /me | 200 | Bearer JWT |
| GET | /users/{user} | 200 | self or admin |
| PATCH | /users/{user}/role | 200 | admin; role customer, support_agent or admin |

Valid register/resend/forgot requests return the exact same message whether the account exists: `If action is needed, an email will be sent.` Invalid input is 422; configured throttles return 429 with Retry-After. Request IDs are always request-specific.

Login returns `{access_token, token_type: "Bearer", expires_in: 600}` at the default TTL and sets `aghigh_refresh`. The refresh credential is not a JSON property. Unknown-email/wrong-password/locked-account login failures share 401 `invalid-credentials`. Only a correct password can reveal 403 `email-not-verified`.

Refresh conflict: 401 `refresh-token-conflict` during grace, no family revocation and **no Set-Cookie**. Do not automatically clear a cookie that another tab just rotated. Later reuse: 401 `refresh-token-reused`, family revoked. Missing/disallowed Origin: 403 `origin-not-allowed`. Unavailable state Redis: 503, never anonymous success.

Use a cookie jar for manual curl tests (`-c /tmp/aghigh.cookies -b /tmp/aghigh.cookies`) and add `Origin: http://localhost:5173` on refresh/logout in the default development configuration. Never paste real access/refresh tokens into tickets or logs. The frontend reset screen is an integration point: read `id`/`token` from the email link and POST them with the new password; no frontend reset page is bundled into the Laravel module.

## Authorization status choices

Valid UUIDs: anonymous 401; unauthorized authenticated caller 403 before resource lookup; authorized missing target 404. This avoids disclosing resource existence through differing unauthorized responses. An invalid UUID does not match the route and yields 404. See `AuthorizationTest.php` for all role/data-provider cases.
