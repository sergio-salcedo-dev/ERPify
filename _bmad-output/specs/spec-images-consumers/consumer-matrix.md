# Consumer matrix

What each story must decide or deliver per consumer. A cell marked **open** is an Open Question of `SPEC.md`; a story resolves it before code and records it where the "Recorded in" column says.

| Axis | Bank logo (ic-3) | User avatar (ic-4) | Bank-account image (ic-5) | Recorded in |
|---|---|---|---|---|
| Holder property | `Bank::$logoImageId` | `User::$avatarImageId` | `BankAccount::$imageId` | entity + migration |
| Write route | `PUT`/`DELETE` on the bank resource | **open** (self, admin, both) | `PUT`/`DELETE` on the account resource | route manifest |
| Write permission | existing bank update permission | **open** | existing bank-account update permission | voter / `IsGranted` |
| Read policy | authenticated back-office user (a logo is not a secret) | **open** (any session vs consumer-scoped route) | follows the open depiction question | read-route ADR D1 |
| Audit type of a read | `BankLogo`, non-person, or unaudited with reason | `UserAvatar`, person-denoting, erasure use case + acceptance scenario | **open** | read-route ADR D2, `api/.audit-resource-types` |
| Cache window | `private, max-age=3600` unless a story argues otherwise | **open** (`no-store` if the deletion SLA is zero) | **open** | read-route ADR D3 |
| `.person-reference-policy` | `non-person` | `non-person` + `#[PersonalData]` | `non-person` (+ `#[PersonalData]` if the image denotes a person) | policy file |
| Release on replace/remove | `ImageDeletionRequested` via the owning use case | same | same | publisher gate registry |
| Release on owner end | `BankDeleter` | `FulfilIdentityErasure` | `BankAccountDeleter`, and `EraseBankAccountSubject` if personal | functional test per path |
| Event contract | **open** (`BankSnapshot` carries the id or not) | none | **open** | event catalog |
| Duplicate/clone path | none may copy the id | none may copy the id | none may copy the id | unit test |
