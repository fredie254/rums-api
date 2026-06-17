# RUMS Security Policy

**Rental Unit Management System (RUMS) — REST API**  
Version 1.0 | Last Updated: June 2026

---

## Table of Contents

1. [Overview](#overview)
2. [User Management APIs](#1-user-management-apis)
3. [Authentication Service](#2-authentication-service)
4. [Access Control Matrix](#3-access-control-matrix)
5. [Security Controls](#4-security-controls)
6. [Data Protection](#5-data-protection)
7. [Incident Response](#6-incident-response)
8. [Security Configuration Reference](#7-security-configuration-reference)

---

## Overview

RUMS uses a two-tier architecture:

| Tier | Location | Purpose |
|---|---|---|
| Frontend | `Rental/` (PHP) | Session-based UI, calls API via ApiClient |
| API | `Rental_api/` (PHP) | Stateless REST API, Bearer token auth |

All data operations go through the REST API. The frontend holds no direct database connection beyond reading configuration. Every authenticated request carries a Bearer token that is validated, scoped, and rate-limited server-side.

---

## 1. User Management APIs

**Base URL:** `/api/v1/`  
**All endpoints require a valid Bearer token unless noted.**

### Endpoints

| Method | Path | Description | Required Role |
|---|---|---|---|
| `GET` | `/users` | List users (search, role, status filters) | admin, manager |
| `POST` | `/users` | Create user account | admin |
| `GET` | `/users/{id}` | Get single user profile | admin |
| `PATCH` | `/users/{id}` | Update name, phone, role, password | admin |
| `PATCH` | `/users/{id}/status` | Set active / inactive / suspended | admin |
| `GET` | `/users/{id}/tokens` | List tokens for a user | admin |
| `GET` | `/tokens` | List all API tokens | admin |
| `DELETE` | `/tokens/{id}` | Revoke an API token | admin |

### Tenant Auto-Provisioning

When `POST /users` is called with `role=tenant`, the system atomically creates both records in a single database transaction:

1. `users` row — login credentials, role, status
2. `tenants` row — profile linked via `user_id`

If either insert fails, both are rolled back. The `tenant_id` is returned alongside `id` in the response. This ensures a tenant user is immediately visible in the tenant list without a separate API call.

### Self-Protection Rule

A user cannot modify their own status via `PATCH /users/{id}/status`. The endpoint returns `403 Forbidden` if `id` matches the authenticated user's ID.

---

## 2. Authentication Service

**File:** `endpoints/auth.php`

### Endpoints

| Method | Path | Auth Required | Description |
|---|---|---|---|
| `POST` | `/auth/login` | No | Exchange credentials for Bearer token |
| `POST` | `/auth/logout` | Yes | Revoke current token |
| `GET` | `/auth/me` | Yes | Current user profile + token metadata |
| `PATCH` | `/auth/profile` | Yes | Update own name and phone |
| `POST` | `/auth/change-password` | Yes | Change own password (requires current password) |
| `POST` | `/auth/token` | Yes | Issue a named API token with specified scopes |
| `DELETE` | `/auth/token/{id}` | Yes | Revoke own token (admin can revoke any) |

### Token Mechanism

**File:** `src/ApiAuth.php`

```
Generation:  bin2hex(random_bytes(32))  →  64-character hex string
Storage:     api_tokens table (hashed? No — stored raw; protect DB access accordingly)
Expiry:      Configurable via API_TOKEN_EXPIRY_DAYS (default: 365 days)
Revocation:  SET revoked = 1 (soft delete, retained for audit)
```

**Validation flow on every request:**

```
1. Extract Bearer token from Authorization header
   └─ Fallback: ?api_token= GET param (GET requests only, convenience)
2. Query api_tokens WHERE token = ? AND revoked = 0 AND expires_at > NOW()
3. Check users.status = 'active' (suspended/inactive users are denied)
4. Touch api_tokens.last_used = NOW()
5. Log request to api_request_logs (token_id, user_id, method, path, IP, user_agent)
6. Cache token and user in static properties for the request lifecycle
```

**On-login token issuance:**

The login endpoint calls `ApiAuth::issueToken()` which assigns scopes based on the user's role (see Access Control Matrix). The token, expiry, and user object are returned in the response body.

### Frontend Session Handling

**File:** `Rental/includes/auth.php`

Sessions are used only in the PHP frontend layer. The session stores the API token and basic user metadata after a successful login call.

| Property | Value |
|---|---|
| Cookie name | `rums_session` |
| Lifetime | 7200 seconds (2 hours) |
| Secure flag | `true` (HTTPS only in production) |
| HttpOnly flag | `true` |
| SameSite | `Lax` |
| Session ID | Regenerated on login (`session_regenerate_id(true)`) |

Session is fully destroyed on logout: `session_destroy()` + cookie cleared + API token revoked.

---

## 3. Access Control Matrix

**File:** `src/ApiAuth.php`

### Enforcement Methods

| Method | Behaviour |
|---|---|
| `ApiAuth::require($db)` | Validates Bearer token; 401 if missing or invalid |
| `ApiAuth::requireScope($db, 'scope')` | Token valid + scope present; 403 if missing |
| `ApiAuth::requireRole($db, 'role', ...)` | Token valid + user role in list; 403 if not |

**Admin bypass:** The `admin` role and the `admin` scope bypass all scope checks. An admin user is permitted on every endpoint.

### Role → Scope Mapping

Scopes are assigned at login time based on role. `write:*` implies the ability to create and update; `read:*` is view-only.

| Role | Scopes Granted |
|---|---|
| **admin** | `admin` (full bypass) |
| **manager** | `read+write`: properties, units, tenants, leases, payments, invoices, maintenance, reports |
| **accountant** | `read`: properties, units, tenants, leases, payments, invoices, reports · `write`: payments, invoices |
| **landlord** | `read`: properties, units, leases, payments, invoices, reports |
| **maintenance** | `read`: units, maintenance · `write`: maintenance |
| **auditor** | `read`: properties, units, tenants, leases, payments, invoices, reports (no write) |
| **security** | `read`: properties, units |
| **tenant** | `read`: leases, payments, invoices · `write`: maintenance (own requests only) |

### Row-Level Access (Tenant Isolation)

Tenants are further restricted at the data level — not just the endpoint level:

- `GET /tenants/{id}` — tenant role may only fetch their own record (matched via `tenants.user_id`)
- `GET /leases` — tenant role receives only leases where `tenant_id` matches their profile
- `GET /invoices`, `GET /payments` — filtered to the authenticated tenant's records
- `GET /maintenance` — filtered to maintenance requests linked to the tenant's unit

### UI Access Control

The frontend sidebar is role-gated. Each role sees only its own navigation section:

| Role | Landing Page | Navigation |
|---|---|---|
| admin | `/dashboard/index.php` | Full system access |
| manager | `/dashboard/index.php` | Properties, tenants, operations, reports (no system/security) |
| landlord | `/landlord/dashboard.php` | Portfolio, income statement only |
| accountant | `/accountant/dashboard.php` | Financials, payments, invoices, reports |
| maintenance | `/maintenance_staff/dashboard.php` | Work orders only |
| auditor | `/auditor/dashboard.php` | Audit trail, compliance, read-only reports |
| security | `/security/dashboard.php` | Visitors, occupancy, incidents |
| tenant | `/tenant/dashboard.php` | My lease, invoices, payments, maintenance |

---

## 4. Security Controls

### 4.1 Password Policy

| Rule | Value |
|---|---|
| Minimum length | 8 characters |
| Algorithm | `PASSWORD_BCRYPT` |
| Cost factor | 10 |
| Verification | `password_verify()` (constant-time) |
| Change requirement | Current password must be provided to set a new one |

Default tenant password format: `Tenant@{last4digits_of_ID}` — users must change this on first login.

### 4.2 Rate Limiting

**File:** `src/ApiAuth.php::rateLimit()`  
**Table:** `api_rate_limits`

- **Algorithm:** Sliding window (per token or per IP for unauthenticated requests)
- **Default limits:** 120 requests per 60-second window
- **Identifier:** Authenticated = `token:{id}`; Unauthenticated = `ip:{address}`
- **Response headers returned on every request:**

```
X-RateLimit-Limit:     120
X-RateLimit-Remaining: 87
X-RateLimit-Reset:     1718612460   (Unix timestamp)
```

- **When exceeded:** `HTTP 429 Too Many Requests` with `Retry-After` header

Adjust limits via `.env`:
```
API_RATE_LIMIT=120
API_RATE_WINDOW=60
```

### 4.3 CSRF Protection

Applies to the PHP frontend only. The API is stateless and uses Bearer tokens instead.

| Aspect | Implementation |
|---|---|
| Token generation | `bin2hex(random_bytes(32))` — 64-char hex |
| Storage | `$_SESSION['csrf_token']` |
| HTML embed | `csrf_field()` → `<input type="hidden" name="_csrf" value="...">` |
| Validation | `hash_equals($_SESSION['csrf_token'], $submitted)` (timing-safe) |
| Scope | Every state-changing form (POST) in the frontend |

### 4.4 SQL Injection Prevention

All database queries in both repos use PDO prepared statements with bound parameters. No string interpolation of user input into SQL queries. Direct string concatenation in queries is limited to trusted internal values (column names from whitelists, not user input).

### 4.5 XSS Prevention

- All output in the frontend is passed through `e()` which calls `htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`
- The API returns JSON only — no HTML rendering
- Content-Security-Policy header is recommended for production (not yet configured)

### 4.6 Request & Audit Logging

**Request log** (`api_request_logs`): Every authenticated API call is logged with token ID, user ID, HTTP method, endpoint path, IP address, user agent.

**Audit log** (`audit_logs`): Business-level events (logins, logouts, record creates/updates/deletes) are logged with old and new values, accessible via `GET /api/v1/audit-logs` (admin and auditor roles only).

### 4.7 Sensitive Key Handling

`GET /settings` returns all settings to authenticated users but strips sensitive keys for non-admin roles:

- `mpesa_consumer_key`
- `mpesa_consumer_secret`
- `mpesa_passkey`
- `smtp_pass`
- `sms_api_key`

These are returned only to users with the `admin` role.

### 4.8 CORS Policy

Allowed origins are configured in `.env`:

```
CORS_ALLOWED_ORIGINS=https://rums.ultimatesolutions.co.ke,https://api.rums.ultimatesolutions.co.ke
```

In development, `*` is acceptable. In production, only explicitly listed origins are permitted. Preflight `OPTIONS` requests are handled before authentication guards run.

---

## 5. Data Protection

### 5.1 Credentials at Rest

| Data | Storage |
|---|---|
| User passwords | BCRYPT hash (never stored in plain text) |
| API tokens | Raw hex stored in `api_tokens` — protect database access |
| M-Pesa keys | Stored in `settings` table — encrypted at DB level recommended |
| SMTP passwords | Stored in `settings` table — encrypted at DB level recommended |

**Recommendation:** Enable MySQL column-level encryption or application-level AES encryption for M-Pesa and SMTP credentials in the `settings` table.

### 5.2 Transport Security

- All production traffic must be served over HTTPS (TLS 1.2+)
- `SESSION_SECURE=true` must be set in production to enforce Secure cookie flag
- HTTP → HTTPS redirect should be enforced at the web server level

### 5.3 Environment File

`.env` must never be committed to version control. It contains database credentials, API keys, and the `APP_KEY`. Ensure `.env` is in `.gitignore`. Use `.env.example` for documentation of required variables.

---

## 6. Incident Response

### 6.1 Compromised Token

1. Admin revokes the token immediately: `DELETE /api/v1/tokens/{id}`
2. Optionally suspend the user account: `PATCH /api/v1/users/{id}/status` → `suspended`
3. Review `api_request_logs` for the compromised token to assess scope of access
4. Re-issue a new token after confirming account integrity

### 6.2 Compromised User Account

1. Suspend account: `PATCH /api/v1/users/{id}/status` → `suspended`
2. Revoke all tokens for the user via admin token list
3. Review audit log for actions taken by the user
4. Reset password after confirming account ownership
5. Reactivate account: `PATCH /api/v1/users/{id}/status` → `active`

### 6.3 Suspected Data Breach

1. Rotate `APP_KEY` in `.env` and restart the application
2. Rotate database credentials
3. Revoke all active API tokens (bulk update: `UPDATE api_tokens SET revoked=1`)
4. Force all users to reset passwords
5. Review `api_request_logs` and `audit_logs` for anomalous activity
6. Notify affected users per applicable data protection regulations (Kenya Data Protection Act 2019)

### 6.4 Brute Force / Rate Limit Abuse

Rate limiting is enforced per token (authenticated) and per IP (unauthenticated). If an IP is identified as a source of abuse:

1. Block at the web server / firewall level
2. Review `api_rate_limits` table for the offending identifier
3. If authenticated, revoke the token

---

## 7. Security Configuration Reference

All security-relevant environment variables:

| Variable | Default | Description |
|---|---|---|
| `APP_ENV` | `production` | Set to `development` only on local machines |
| `APP_DEBUG` | `false` | Never `true` in production — exposes stack traces |
| `APP_KEY` | *(required)* | 64-char random string; used for internal signing |
| `API_RATE_LIMIT` | `120` | Max requests per rate window |
| `API_RATE_WINDOW` | `60` | Rate window size in seconds |
| `API_TOKEN_LENGTH` | `64` | Token byte length (hex output = 2× this) |
| `API_TOKEN_EXPIRY_DAYS` | `365` | Token lifetime in days; `0` = never expires |
| `CORS_ALLOWED_ORIGINS` | *(required)* | Comma-separated list of permitted origins |
| `SESSION_SECURE` | `true` | Set `false` only for local HTTP development |
| `SESSION_HTTPONLY` | `true` | Always `true` in production |
| `SESSION_SAMESITE` | `Lax` | `Strict` for higher security, `Lax` for usability |

### Recommended Production Checklist

- [ ] `APP_DEBUG=false`
- [ ] `APP_ENV=production`
- [ ] `APP_KEY` is a unique 64-char random string
- [ ] `.env` is not readable from the web (deny at web server level)
- [ ] HTTPS enforced with valid TLS certificate
- [ ] `SESSION_SECURE=true`
- [ ] `CORS_ALLOWED_ORIGINS` lists only your domains
- [ ] Database user has minimum required privileges (no `DROP`, `CREATE`)
- [ ] `storage/logs/` is not publicly accessible
- [ ] M-Pesa `mpesa_env=live` only when go-live is confirmed

---

*For security vulnerabilities, contact the system administrator. Do not disclose vulnerabilities publicly before they are patched.*
