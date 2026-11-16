# Consumer matrix

What each story must decide or deliver per consumer. A cell marked **open** is an Open Question of `SPEC.md`; a story resolves it before code and records it where the "Recorded in" column says. Routes and error types are in `wire-contract.md`.

| Axis | Bank logo (ic-3) | User avatar (ic-4) | Bank-account image (ic-5) | Recorded in |
|---|---|---|---|---|
| Holder property | `Bank::$logoImageId` | `User::$avatarImageId` | `BankAccount::$accountImageId` | entity + migration |
| Write route | `PUT`/`DELETE …/logo` | `PUT`/`DELETE …/avatar` | `PUT`/`DELETE …/image` | route manifest |
| Write permission | existing bank update permission | **open** (self, admin, both) | existing bank-account update permission | voter / `IsGranted` |
| Owner lock | `findByIdForUpdate` (added) | `findByIdForUpdate` (exists) | `findByIdForUpdate` (exists) | repository + functional test |
| Read route | shared `GET /images/{imageId}` | owner-scoped `GET …/users/{id}/avatar` | shared (follows the open depiction question) | read-route ADR D1 |
| Read audience | authenticated session, recorded in the ADR | **open** (user, admin, any back-office session) | follows the open depiction question | read-route ADR D1, gate registry |
| Audit of a read | `BankLogo`, non-person, or unaudited with reason | `UserAvatar`, person-denoting; **open** whether audited, sampled or write-only | **open** | read-route ADR D2, `api/.audit-resource-types` |
| Audit of a write | none (the entity's own audit) | explicit `AuditLogger` row `UserAvatar`, no image id, same transaction | none unless the open question says person | use case + acceptance scenario |
| Cache window | `private, max-age=3600` unless a story argues otherwise | **open** (`no-store` if the deletion SLA is zero) | **open** | read-route ADR D3 |
| Resource DTO field | `logoImageId` | `hasAvatar` (boolean, never the id) | `accountImageId` | Resource DTO + golden test |
| `.person-reference-policy` | `non-person` | `non-person` | `non-person` | policy file |
| Release on replace/remove | shared replace service queues `ImageDeletionRequested` | same | same | publisher gate registry |
| Release on owner end | `BankDeleter` (refuses a bank with accounts; no cascade) | `FulfilIdentityErasure`, result category `avatar_release_queued` | `BankAccountDeleter` and `EraseBankAccountSubject`, both unconditional | functional test per path |
| Event contract | **open** (no event, or a separate event preserving null) | none | **open** | event catalog |
| Duplicate/clone path | none may copy the id | none may copy the id | none may copy the id | unit test |
