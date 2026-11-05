```
/bmad-help continue the Documents bounded-context epic (issue #268) after review round 8. The spec is merged; the next step is a choice the owner has not made yet — ask first (see NEXT).

BRANCH: docs/documents-context-handoff-ntnn (carries only this handoff, no PR; branched from origin/main 1c1e8e78). The spec work itself is on main: PR #1194 (rounds 5-6 and decisions) and PR #1197 (round 8), both merged. Issue #268 is still open.
DONE:
- Spec folder `_bmad-output/specs/spec-documents-context/` (SPEC.md, evidence-lifecycle.md, stories.yaml with doc-1..doc-10, append-only .memlog.md) went through review rounds 5, 6, 7 and 8 (three parallel read-only layers each: Blind Hunter, Edge Case Hunter, Acceptance Auditor). Every `patch` was applied through the memlog and the three files re-derived. Rounds produced ~40, 44, 36 and ~38 findings; most of each round came from the previous round's patches.
- ADR `docs/adr/images-vs-documents-conservation-contract.md` amended (filename is a validated sealed value, never a location).
- PR #1197 merged by the owner on 2026-11-05 (merge commit 1c1e8e78): all checks green, SonarCloud gate passed, no open review threads. Nothing is pending on it.
IN PROGRESS: nothing. No code exists for the Documents context; the work so far is guidelines only.
NEXT (ask the owner which; do not start either unprompted):
1. Recommended: stop reviewing and implement. Start doc-1 (stream-oriented envelope encryption in `Shared/Crypto`) in a new worktree: `make worktree.create BASE=origin/main`, branch `feat/shared-documents-doc-1-<slug>` or similar. Review findings from here come better from tests and gates than from another reading of the text. Per root CLAUDE.md, creating the branch and opening the PR need no OK; merging does.
2. Alternative: one short round 9 aimed only at the round-8 patches (filename carrier, redaction by key name, ledger invocation/credentials/completion sweep), not a general read of the spec. Expect it to find things; the owner decides if that is worth it.
3. Housekeeping: `_bmad-output/handoffs/documents-context/general/20261104-195830-documents-spec-review-round-5.md` and the two older handoffs in that folder are stale history; they are deleted with the folder once the epic is closed.
ARTIFACTS (re-read):
- `_bmad-output/specs/spec-documents-context/SPEC.md` (canonical contract; §7 residuals)
- `_bmad-output/specs/spec-documents-context/evidence-lifecycle.md`
- `_bmad-output/specs/spec-documents-context/stories.yaml` (ten stories; no status field, dispatch state would live in `stories/<id>-*.md`)
- `_bmad-output/specs/spec-documents-context/.memlog.md` (decisions R5-1..R8-6 plus supersession notes; append only via `uv run _bmad/scripts/memlog.py append --workspace … --type decision|note|question|event --text …`, never by hand)
- `docs/adr/images-vs-documents-conservation-contract.md`
- `docs/adr/bank-bankaccount-modeling.md` (persistence-strategy decision style)
KEY PATHS:
- `api/src/Shared/ErrorContract/Application/RequestUriRedaction.php` (redacts by key name; `filename` must be added when the upload lands)
- `api/frankenphp/Caddyfile` (access log strips the query, logs all headers except `Referer`, and the path in clear)
- `api/.person-reference-policy`, `api/.persistent-transport-policy`, `api/.audit-resource-types`, `api/.route-manifest.json`, `api/.credential-proof-policy`, `api/.bounded-context-allowlist`, `api/tools/deptrac/deptrac.yaml` (registries the stories must update)
- `docs/api-error-contract.md`, `docs/vps-deployment.md`, `PRODUCTION_SECURITY_CHECKLIST.md` (§7 residuals; doc-7 must update the named passages)
DECISIONS/CONSTRAINTS:
- Evidence-class binaries are stored untransformed; state-oriented `Document` snapshot plus append-only `document_custody` log; per-document `EncryptionScopeId` with crypto-shredding by key destruction; tombstones carry a `reason`.
- Two-step upload (declare, then raw-body PUT with mandatory Content-Length); upload-intent state machine Declared/Streaming/Committed/Expired; cap on Streaming intents serialised by advisory locks; scan via ClamAV `clamd` after sealing with `scan_attempts` (cap 3) and `scan_outage_returns` (cap 10, alarm at 5).
- Global lock order: intent rows, then documents (with their custody rows), then keystore rows, ascending id. Person erasure runs inside the identity erasure transaction.
- Original filename: persisted sealed under the document's own scope (owner decision), carried only as a percent-encoded `filename` query parameter of the PUT, never a header, path or declare-step field; validated before claim; `Document` is not `#[AuditedEntity]`.
- Erasure ledger (owner, 2026-11-05): one copy only, fails closed, with an explicit override procedure; recorded as a §7 residual. First adapter is restic behind an append-only REST server on a second host.
- Open by design, not a defect of the text: the doc-9 successor-exclusion mechanism (holds_slot design superseded; SPEC lists the properties either design must keep), the ledger drill and the `restore.prod` change (they block doc-7 only), provisional constants, custody against a PostgreSQL administrator, the CAP-8 search surface.
- Merging is the owner's per PR (`/merge-when-green <pr>`); never merge or force-push `main`. The conversation with the owner is in Spanish; commits, PRs, docs and spec are in English.
- Another session (erpify-9c) has been editing the Documents work; it was told the settled filename carrier and the ledger decision. Check `git log origin/main` before editing the spec folder, and ask before touching files if the tree is dirty on arrival.
```
