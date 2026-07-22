# Changelog

All notable, operator- or user-facing changes to ERPify are recorded here. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). This
project does not yet cut versioned releases, so entries live under **Unreleased**
until the first tag; behaviour changes that affect operators or on-call are marked
**BREAKING**.

## [Unreleased]

### Changed

- **BREAKING (operators) — `identity:gdpr:erase-subject` is no longer identity-only.**
  The GDPR erase CLI now also anonymises the audit trail (`actor_id` / `ip` /
  `user_agent`), purges the subject's sessions, and enforces the ≥1-active-admin
  guard (erasing the last active admin now fails). A `SECURITY` self-audit failure
  inside the erase transaction rolls back the **entire** operation — erase is
  all-or-nothing. Previously the command erased only the identity, leaving an orphan
  `actor_id` in the trail. `audit:gdpr:erase` (actor-only) is unchanged. (#529)

### Added

- **Users administration console** (`/backoffice/users`) connected to the real
  Iam/Identity backend: paginated list + detail, invite (invitation-based signup),
  status change (suspend / deactivate) with a ≥1-active-admin guarantee, and role
  editing — each gated by a distinct `users.*` permission (ADMIN-only). (#501–#508)
- **GDPR erase from the console** — `DELETE /backoffice/users/{id}` (ADMIN-only,
  type-to-confirm) runs the full erasure atomically; erasing your own identity is
  refused (`409 self-erasure-forbidden`). The CLI remains available. (#529)
- **Derived permissions on `/me`** — the response now carries the caller's derived
  permission set so the console can gate controls client-side with `<Can>`. (#503)

### Removed

- **The asynchronous `activity` audit queue** (`RecordAuditEntry` message + the
  `audit` Messenger transport). Audit `activity` entries are now written
  synchronously, closing the window where an in-flight message could re-insert an
  already-anonymised `actor_id`, and eliminating the second durable copy of PII in
  `messenger_messages` / the `failed` transport. (#524, closes #376)

### Fixed

- `ProblemDisplay` announces transport ("no response") errors assertively to screen
  readers instead of politely (`aria-live`). (#531)

### Security

- Request bodies carrying undeclared members are now rejected on every endpoint. (#519, #520)

[Unreleased]: https://github.com/sergio-salcedo-dev/ERPify/commits/main
