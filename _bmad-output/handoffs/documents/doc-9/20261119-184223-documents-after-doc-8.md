# Handoff — Documents epic (#268) after doc-8 closed

```
/bmad-agent-dev continue the Documents epic (#268): doc-8 is shipped and done; start doc-9 (document versioning) from its stories.yaml entry, after checking the open owner items below.

BRANCH: start doc-9 on a fresh worktree off origin/main (make worktree.create BRANCH=feat/documents-versioning BASE=origin/main). This handoff rides ccr-53aa5da9-vt20cv, whose PR #1270 is merged.

DONE (all merged to main):
- doc-8 dependent derivatives: #1269 (squash 8b55c574). Origin on `image` (origin_type/origin_id, both-or-neither CHECK), MaterialiseDerivative under an OriginGuard (Documents: FOR SHARE, Ready only), ReleaseDerivatives + read-only DerivativeOrigins, the 5-minute ReleaseErasedDerivatives tick (re-request 4 h, alarm 8 h, derived by DerivativeReleaseBoundsTest from the async retry_strategy), the 4-hourly SweepOrphanDerivatives, the fail-closed image route, and document.erasure_reopened_at so both erasure alarms measure from a restore's reopen. Three review layers plus two adversarial passes recorded in the story file.
- #1270 (squash bf0668af): doc-8 story marked done; six memlog entries written through memlog.py on the owner's explicit request; stories.yaml re-derived (doc-8, doc-9 updated; doc-10 removed; doc-11, doc-12 added; 11 entries); SPEC.md moves CAP-8 to "Deferred capabilities".
- Issue #268 status block updated to the delivered state.

IN PROGRESS: nothing. No branch or worktree holds unshipped work.

NEXT (in order):
1. Ask the owner about the open items before starting code: (a) the doc-7 restore drill on the second host and its `restore`/`drill` proof records (doc-7 stays `in-review` until the drill record is committed; documents.ingest_enabled / read_enabled stay closed in production until then); (b) PR #1268, still open, which only adds sections 9-10 to the doc-8 plan handoff — its decisions are already in the spec, so it can be merged or closed; (c) #718, which section 10 of that plan closes with its own small PR (audit prune nulls ip/user_agent on exempt rows past their level's threshold).
2. doc-9: read its stories.yaml entry and the memlog line "doc-9 successor exclusion decided"; design B (series row, head pointer moved by one UPDATE under the series lock) is decided, as are RBAC authorisation (document.write, no row-level voter) and the tombstone keeping uploader_id under the person purge. Before modelling, confirm the persistence strategy with the owner (state-oriented expected), then implement, run the three review layers and record the adversarial pass.
3. After doc-9: doc-12 (first consumer, BankAccount evidence + PWA), then doc-11 (preview producer on a secret-free rendering sidecar).

ARTIFACTS (re-read):
- `_bmad-output/specs/spec-documents-context/SPEC.md`, `stories.yaml`, `.memlog.md` (last six entries), `evidence-lifecycle.md`
- `_bmad-output/specs/spec-documents-context/stories/doc-8-dependent-derivatives.md` (Spec Change Log, Review Triage Log)
- `_bmad-output/handoffs/documents/doc-8/20261119-072425-doc-8-plan.md` (sections 1-8 on main; 9-10 only on PR #1268's branch ccr-38e5efdc-b2nhi8)
- `docs/adr/images-vs-documents-conservation-contract.md` (D8), `docs/adr/documents-custody-log.md`
- `PRODUCTION_SECURITY_CHECKLIST.md` §7 (doc-8 residuals)

KEY PATHS:
- `api/src/Documents/Document/` (Application/Erasure, Application/Derivative, Domain/Erasure, Infrastructure/Persistence/Doctrine)
- `api/src/Shared/Images/` (Domain/DerivativeOrigin.php, Application/MaterialiseDerivative.php, ReleaseDerivatives.php, DerivativeOrigins.php)
- `api/migrations/2026/Version20261119071028.php`
- `api/tests/Functional/Documents/Document/DocumentDerivativesFunctionalTest.php`, `DocumentDerivativeLifecycleFunctionalTest.php`, `ErasureSweepsFunctionalTest.php`

DECISIONS/CONSTRAINTS:
- Never merge without the owner's explicit per-PR permission (/merge-when-green <n>).
- Memlog entries are written only through `_bmad/scripts/memlog.py`; entries tagged "decision by user" need the owner's explicit say-so.
- doc-10 (OCR, CAP-8) left the epic as an argued deferral; its shape is in SPEC.md "Deferred capabilities".
- Order decided by the owner: restore drill, doc-8 (done), doc-9, doc-12, doc-11.
- A cloud session has no docker compose stack: run gates in a local PHP 8.5 toolbox against a throwaway Postgres; the live-stack (Caddy) tests error there by design and are left to CI.
```
