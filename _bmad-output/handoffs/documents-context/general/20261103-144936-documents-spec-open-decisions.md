# Handoff — Documents bounded context spec (#268), open decisions

```
/bmad-spec update the Documents spec in `_bmad-output/specs/spec-documents-context/` once the user answers the open questions below. Guidelines only, no code.

BRANCH: docs/handoff-documents-spec-7kh2 (this handoff only; no PR). The spec itself is on `main`.
DONE:
- The user decided on 2026-10-30: state snapshot `Document` plus append-only `document_custody` log; each version is its own `Document` aggregate; local filesystem adapter first. Recorded in PR #1146 (merged).
- PR #1179 (merged, squash `70c02b12`) applied three rounds of the three-layer review to the spec: ingest ordering with an upload-intent record, "a committed row owns its staged object", `lease_epoch` scan leases, keystore tombstones with a `reason`, a shared intent-row lock between the commit and the reaper (database clock), sweeps that never delete in the final namespace, an amended `DbalSubjectErasureReconciler` (doc-1), `UNIQUE (scope_id)`, a `Scanning` to `ScanFailed` edge, voter on the predecessor of a new version.
- Stories doc-1..doc-10 in `stories.yaml`, dependencies written into the descriptions.
IN PROGRESS: nothing. No code exists for this context.
NEXT (the user decides, in this order of blocking):
1. Ciphertext of an `Erased` document: delete the sealed object after commit (re-drivable sweep plus alarm) or keep it on purpose. Blocks doc-7.
2. Empty and oversize originals: accept zero-length? value of `MAX_ORIGINAL_BYTES` (also the scanner limit). Blocks doc-3.
3. Scan retry: does "scanner unavailable" count toward the attempt cap; scan lease by heartbeat or scaled to size; the cap itself.
4. Older open questions still unanswered: key resurrection on restore (ledger outside PostgreSQL, change to `restore.prod`; blocks doc-7), actor pseudonymisation in custody (blocks doc-2), host context and first consumer, scanner choice and scan order (before or after sealing), retention and legal-basis vocabulary, misfiling guard placement, version slot, subject erasure over versions (blocks doc-9), operator surface, tombstone growth.
5. After answers: append each decision to the memlog with `uv run _bmad/scripts/memlog.py append --workspace _bmad-output/specs/spec-documents-context --type decision --text "…"` (never by hand), re-derive `SPEC.md` and `stories.yaml`, then run the three review layers (read-only, absolute worktree path, read PR threads first) before merge.
ARTIFACTS (re-read): `_bmad-output/specs/spec-documents-context/SPEC.md`, `evidence-lifecycle.md`, `stories.yaml`, `.memlog.md`; `docs/adr/images-vs-documents-conservation-contract.md`; `docs/adr/image-deletion-signal-transport.md`.
KEY PATHS: `api/src/Shared/Crypto/Application/Keystore.php`, `api/src/Shared/Crypto/Infrastructure/Persistence/DbalKeystore.php`, `api/src/Shared/Crypto/Infrastructure/SodiumEnvelopeEncryptor.php`, `api/src/Shared/Audit/Infrastructure/Persistence/DbalSubjectErasureReconciler.php`, `api/src/Shared/Event/Infrastructure/Persistence/DbalEventStore.php`, `api/migrations/2026/Version20260616201857.php`.
DECISIONS/CONSTRAINTS:
- Standing rule: no merge without the user's `/merge-when-green <pr>`; new branches need the user's explicit authorization.
- Persistence strategy and aggregate boundaries are the user's calls; scan order was deliberately NOT decided by the review (the scanner constraint is conditional: plaintext never reaches disk or a spool, whichever order).
- Verified in the tree: `DbalKeystore::destroy()` writes no tombstone for a never-minted scope (doc-1 fixes it); the `Keystore` port has no lock or tombstone query; `KEK_VERSION` is a private constant of the encryptor; no DB roles, GRANT or triggers exist, so custody enforcement is a source gate only.
- Unrelated threads that live elsewhere: issue #256 (open until the user decides), #1017 (needs the user's Sentry auth token, set by the user), #1136 (waits on the owner's path choice, Aikido). A backup/restore policy consultation prompt for an external AI exists only on the original machine under `tmp/bmad-md/consult-backup-restore-policy-20261030-090144.md` (gitignored, not in the repo) and awaits its answer.
```
