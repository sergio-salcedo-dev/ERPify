# Security Best Practices

## General Security
- Validate and sanitize all user inputs
- Use parameterized queries/prepared statements
- Implement proper authentication and authorization
- Follow principle of least privilege
- Never trust user input
- Keep dependencies up-to-date
- Use HTTPS in production
- Implement CSRF protection

## Security Checklist Maintenance
- The `PRODUCTION_SECURITY_CHECKLIST.md` file MUST be kept up-to-date at all times
- When making changes that affect security-related files, I MUST review and update the checklist accordingly
- If adding new security-sensitive files or configurations, I MUST add corresponding entries to the checklist
- If modifying files referenced in the checklist, I MUST verify the checklist items are still accurate
- The checklist should reflect the current state of the codebase, not just production deployment requirements

## Pre-Commit Security Checks

Before ANY commit, I MUST perform security checks on all changed files:

### General Security Checks
- [ ] No hardcoded passwords, API keys, tokens, or secrets in code
- [ ] No `.env` files or sensitive configuration files are being committed
- [ ] No debug code (var_dump, print_r, console.log) left in production code
- [ ] No commented-out code containing sensitive information
- [ ] No test credentials or mock secrets in committed code

### File-Specific Security Checks

#### Docker/Nginx Configuration Files
- [ ] No default passwords or credentials
- [ ] CORS origins are properly configured (not wildcard `*` unless necessary)
- [ ] Server names are not catch-all (`_`) in production configs
- [ ] SSL/TLS configuration is secure
- [ ] Security headers are properly configured
- [ ] Rate limiting is implemented where appropriate
- [ ] Xdebug is disabled in production configurations

#### PHP Files
- [ ] All user inputs are validated and sanitized
- [ ] SQL queries use prepared statements (no string concatenation)
- [ ] No eval() or dangerous functions without proper sanitization
- [ ] Error messages don't leak sensitive information
- [ ] File uploads are properly validated
- [ ] Authentication and authorization checks are in place
- [ ] CSRF protection is implemented for forms

#### Environment/Configuration Files
- [ ] No `.env` files are committed (check `.gitignore`)
- [ ] Default values are changed from insecure defaults
- [ ] Production environment variables are not exposed

#### Database Files
- [ ] No test data or development credentials in migration scripts
- [ ] Database initialization scripts use parameterized queries
- [ ] User permissions are properly configured
- [ ] No sensitive data in seed files
- [ ] Deletes are hard deletes — soft delete only under the documented exceptions in [`database.md`](database.md) (GDPR erasure must stay satisfiable)
- [ ] `identity_user` holds a **credential** (`password_hash`) and **PII** (`email`): the `password_hash` is never logged, returned, serialized, or audited — `User` deliberately does **not** implement `AuditedEntity`, so it stays out of the `onFlush` change diff (a credential leak), and the domain VO `HashedPassword` is opaque to the algorithm (hashing lives in Infrastructure). `User` is **hard-deleted** (no soft delete), keeping GDPR erasure of the email satisfiable. See ADR [`../adr/auth-rbac-subsystem.md`](../adr/auth-rbac-subsystem.md)
- [ ] **Auth failures flow through the RFC 9457 pipeline, never a manual `JsonResponse`.** The session firewall's `json_login` failure handler **re-throws** so `ExceptionResponder` builds the 401 `unauthenticated`; the message is **normalised** to one string so unknown-email and wrong-password are indistinguishable (no user enumeration — keep `hide_user_not_found` on). The session cookie is **httpOnly + `SameSite=Lax` + `Secure`**; login CSRF (forced login) is closed by a same-origin `Origin` guard on the login POST (`LoginOriginListener`) — `json_login` fires on the route's `_format: json` default, **not** the `Content-Type`, so a cross-site `text/plain` form carrying a JSON body would otherwise reach it as a CORS simple request, and neither `SameSite=Lax` nor the CORS policy stops forced login (they gate reading the response, not sending the request). `json_login` validates no token; stateless-token CSRF for mutating routes stays wire-on-consumer. CORS / Mercure are not broadened. **`access_control` is default-deny:** every `/api` route needs an authenticated session unless explicitly allowlisted (login, health, dev hot-reload); an unauthenticated hit on a protected route becomes a **401 `unauthenticated`** via `UnauthenticatedAccessListener`, which rewrites the firewall's `AccessDeniedException`→`AuthenticationException` for anonymous callers (401, not 403) while an authenticated-but-forbidden caller still gets 403 (Epic 3 adds `#[IsGranted]` on the audit read routes). Media/object routes are protected, not public. See ADR [`../adr/auth-rbac-subsystem.md`](../adr/auth-rbac-subsystem.md)
- [ ] `audit_log` is a **PII table** (`actor_id`, `ip`, `user_agent`); its `ip` / `user_agent` / `metadata` are client-controlled (tainted — escape on render, never trust). GDPR erasure is implemented as an in-place irreversible anonymisation (`audit:gdpr:erase`: `actor_id` → one fresh random UUID per subject, `ip` / `user_agent` → `[REDACTED]`, and the materialised non-PII `actor_erased` flag set — all in one `UPDATE`; never row deletion; self-audited as a `security` `GDPR_ERASURE_EXECUTED` entry holding only the pseudonym). Retention-by-level (scheduled prune) is tracked separately — see [`../../PRODUCTION_SECURITY_CHECKLIST.md`](../../PRODUCTION_SECURITY_CHECKLIST.md). ISO 27001:2022 base mapping: **A.8.15** (append-only event log + restricted access) and **A.8.17** (clock-synchronised `occurred_on`, sealed from the system clock). The write-side `change` diff is surfaced read-only via the canonical `GET /audit/events/{id}` resource and rendered as **escaped text** in the investigation UI — never `dangerouslySetInnerHTML`
- [ ] **PII in a write diff is crypto-shredded, never stored in clear** (A.5.12, ADR [`../adr/regulatory-audit-trail.md`](../adr/regulatory-audit-trail.md)). Owning modules classify personal-data fields with a passive `#[PersonalData]` attribute (`BankAccount`: `holderName`/`iban`; `Bank`: none); the `onFlush` capture AEAD-encrypts (libsodium) those diff columns under a per-subject DEK (envelope: DEK wrapped by the env-custodied `AUDIT_KEK`, kept in a Postgres keystore keyed by `EncryptionScopeId`), leaving non-PII in clear. The row references its `encryption_scope_id`; the read UI shows a sealed sentinel, never the ciphertext. **Subject erasure** (`bank-account:gdpr:erase-subject`) destroys the DEK — the ciphertext is permanently unreadable, the append-only rows survive — and is **never merged** with actor erasure (`audit:gdpr:erase`): distinct GDPR triggers, distinct loci (ADR D15). The `change` level has a 5-year retention floor (regulatory evidence)

#### Docker Files
- [ ] Base images are from trusted sources
- [ ] No unnecessary packages installed
- [ ] Containers run as non-root users where possible
- [ ] No exposed ports without proper justification

### Security Checklist Update Process
When committing changes that affect security:
1. Review all changed files against the security checks above
2. Check if any changed files are referenced in `PRODUCTION_SECURITY_CHECKLIST.md`
3. If files are referenced, verify checklist items are still accurate
4. If new security concerns are introduced, add them to the checklist
5. If security issues are fixed, update the checklist accordingly
6. Document any security-related changes in commit messages

### Security Issue Detection
If I detect any security issues during pre-commit checks:
- I MUST alert the user immediately
- I MUST NOT proceed with the commit until the issue is addressed
- I MUST suggest specific fixes for the security issue
- I MUST update `PRODUCTION_SECURITY_CHECKLIST.md` if a new issue type is discovered

### Security Checklist Reference
- Always refer to `PRODUCTION_SECURITY_CHECKLIST.md` for comprehensive security requirements
- The checklist contains specific file paths and line numbers for security-critical items
- Use the checklist as a guide when reviewing code changes
- Update the checklist when security configurations change
