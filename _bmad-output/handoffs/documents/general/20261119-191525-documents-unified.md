# Handoff — Documents epic (#268): unified (replaces the doc-8 plan, the doc-9 handoff and the session-close handoff)

```
/bmad-agent-dev continue the Documents epic (#268): finish doc-9 and #718 (both in flight as separate PRs), then doc-12 and doc-11, after the owner's open items below.

BRANCH: this handoff rides claude/local-resume-handoff-doc-9-35xvqf (its own docs PR). Work branches:
- doc-9: feat/documents-versioning-24nf (worktree .claude/worktrees/documents-versioning-24nf, off origin/main 98d6d449)
- #718:  fix/shared-audit-exempt-ip-retention-dvro (worktree .claude/worktrees/shared-audit-exempt-ip-retention-dvro)
A cloud container is ephemeral: if those branches are not on origin, that work was lost and restarts from the briefs below.

DONE (merged to main):
- doc-8 dependent derivatives: #1269 (8b55c574); doc-8 closed and spec re-derived: #1270 (bf0668af).
- Plan sections 9-10 (remaining epic, #718 policy, doc-12 decisions 1-7): #1268 (98d6d449). Their decisions are in
  .memlog.md (lines 190-192) and stories.yaml (doc-9, doc-11, doc-12); the plan file itself is deleted by this
  handoff's commit and stays readable with `git show 98d6d449:_bmad-output/handoffs/documents/doc-8/20261119-072425-doc-8-plan.md`.

DECIDED THIS SESSION (2026-11-19, by the owner):
- doc-9 persistence: state-oriented (no event sourcing), after an emulated architect consultation (Winston); Codex
  was not consulted (no CLI in the container) and the owner decided without it.
- doc-9 successor exclusion refined to "option C": the document_series row is ONLY a mutex taken by the two
  transitions that take the slot (new version, reopen of a ScanFailed child); NO stored head pointer. Under the lock
  the take checks "predecessor P has no live strict descendant" (live = Received/Scanning/Ready) by a lock-free
  recursive read. A node only becomes live under the lock, so a concurrent free costs at most a retryable false 409.
  Erasure, the scan compare-and-set, the cap sweep and ReplayErasure are untouched; ErasureLimits::TRANSACTION_LOCKS
  stays 3. Supersedes the head pointer of memlog line 190 (design B). Recorded in the doc-9 ADR decision and story
  file; the memlog line is NOT written yet (see NEXT 1).

IN PROGRESS (state at 2026-11-19 ~20:40 UTC):
- #718 DONE: PR #1273 (6c8266af + 83704953), three review layers run and 15 patches applied, CI fully green,
  `Closes #718`; waits only for the owner's merge permission. After it merges the §7 accepted-risk register is empty.
- doc-9 IMPLEMENTED, NOT REVIEWED: branch feat/documents-versioning-24nf pushed at 356c636a (68 files) WITHOUT a PR.
  Gates: full PHPUnit green except the live-stack Caddy tests, Behat features/documents green (70 scenarios),
  php.quality green except php.stan, which failed only on the toolbox's cache path (full-tree PHPStan from /app/api
  green). The three review layers were launched and STOPPED by the owner (subscription limit) before reporting:
  rerun all three (Blind, Edge, Acceptance) in parallel, read-only, on 356c636a; the implementer's 12 design
  decisions are listed in the story file `stories/doc-9-document-versioning.md`, pass them as already known.
  Owed in the same PR together with the layers' patches (owner's direction 2026-11-19: reduce accepted risks to zero,
  doc-9's §7 block must not end as "Accepted"):
  1) series erasure terminates: at start it takes the series row lock once and marks the series closed
     (e.g. document_series.closed_at); TakeVersionSlot refuses a take on a closed series. Outside the per-version
     erasure transactions, so TRANSACTION_LOCKS stays 3. Test: a version filed during a series erasure is refused.
  2) the false 409 is the option-C contract, not a risk: reword it in §7/ADR D12 as a retryable conflict.
  3) DECIDED by the owner: POST /api/v1/documents/{id}/versions requires document.write AND the document read
     permission (still RBAC, no row-level voter); remove that residual from §7.
  4) the series page naming uploaders to ADMIN mirrors the listing: state it, do not mark it accepted.
  Remove the "Accepted — the owner's design" line: the owner accepted option C, not these residuals.
  Then record the adversarial pass in the story file, push, open the PR (REST; GraphQL blocked), never merge.
- Local leftovers in the dead container (irrelevant after it is gone): toolbox containers erpify-24nf-*, image
  erpify-dvro-toolbox.

NEXT (in order):
1. Owner, locally with _bmad/scripts/memlog.py (absent from cloud containers; never hand-edit .memlog.md):
   a) write the doc-9 "successor exclusion refined" line (exact text in the doc-9 story file's Spec Change Log,
      "To apply locally with memlog.py", and in the doc-9 PR body) and re-derive the doc-9 entry of stories.yaml;
   b) DONE in #1272: the references to the deleted plan are re-pointed (stories.yaml doc-8 entry, the doc-8 story
      file's Spec Change Log) and, since the memlog is append-only, a `(note)` entry appended through memlog.py
      redirects line 187 to #1267/#1268. memlog.py came from the npm package bmad-method@6.12.4
      (src/scripts/memlog.py); in a cloud session the doc-9 decision entry was blocked by the session's permission
      classifier because it records a decision in the owner's name.
2. Owner: the doc-7 restore drill on the second host and its restore/drill proof records. doc-7 stays `in-review`
   until the drill record is committed; documents.ingest_enabled / read_enabled stay closed in production until then.
3. Owner, open items carried over (none approved yet):
   a) ic-5 ("Bank-account image", spec-images-consumers): what does the image represent? If it has no real use,
      retire ic-5 (spec + its code residue, own PR with php.quality and pwa.quality) and doc-12 drops the image-control
      notice but records the reopening condition in ADR D3; if it stays, ic-5 inherits doc-12's D3 guard (stories.yaml
      doc-12 already says so). Residue map: `git show 98d6d449:_bmad-output/handoffs/documents/general/20261119-081047-session-close-external-consult.md` (KEY PATHS).
   b) the drill proof record gains a `code_ref` (commit) field, because doc-8/doc-9 change the replay and the record is
      bound to no code version (third architect round, recommended, not approved).
   c) the ChatGPT consultation of the session-close handoff is still open (no answer recorded). Its prompt is in that
      file's appendix (git show above). Decide whether to run it or drop it. The order it questioned is already
      recorded in the memlog: drill, doc-8, doc-9, doc-12, doc-11.
4. doc-12 (first consumer: BankAccount evidence + PWA), from its stories.yaml entry. Brief for subagents, ONE worktree
   off origin/main and ONE PR: subagent API (BankAccountEvidence link table, the retention-per-kind seam Documents
   publishes, the 409 on erasing an account with live evidence, the DocumentNameOpener read port + allowlist +
   deptrac skip_violations, ADMIN only) and subagent PWA (raw two-step upload against the real Caddy bounds,
   encodeURIComponent filename, mirrored redaction, list/download, the D3 guard) on disjoint files; checkpoint
   decision owed: whether EraseBankAccountSubject takes the same 409 or erases the documents first. Owes the three
   review layers and a recorded adversarial pass.
5. doc-11 (preview producer on a secret-free rendering sidecar), after doc-12: sequential, it depends on doc-12.

ARTIFACTS (re-read):
- `_bmad-output/specs/spec-documents-context/SPEC.md`, `stories.yaml`, `.memlog.md` (lines 185-192), `evidence-lifecycle.md`
- `_bmad-output/specs/spec-documents-context/stories/doc-9-document-versioning.md` (once its PR exists)
- `docs/adr/documents-custody-log.md` (D1, D6, D8, D11 and the doc-9 decision), `docs/adr/images-vs-documents-conservation-contract.md` (D3, D8)
- `_bmad-output/specs/spec-images-consumers/SPEC.md`, `stories.yaml` (ic-5)
- `PRODUCTION_SECURITY_CHECKLIST.md` §7

KEY PATHS:
- `api/src/Documents/Document/` (Domain/Entity/Document.php, Domain/Erasure/ErasureLimits.php, Application/Erasure/ApplyErasure.php,
  Infrastructure/Persistence/Doctrine/DbalDocumentScanLeases.php)
- `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditLogPruner.php`, `api/src/Shared/Audit/Domain/AuditErasureEvidence.php`
- `api/src/Backoffice/BankAccount/Application/EraseBankAccountSubject.php`

DECISIONS/CONSTRAINTS:
- Never merge without the owner's explicit per-PR permission (/merge-when-green <n>).
- .memlog.md only through memlog.py; entries tagged "decision by user" need the owner's say-so; bmad-build-auto reads
  only stories.yaml, so a decision is not live for it until re-derived there.
- doc-10 (OCR, CAP-8) left the epic as an argued deferral (SPEC.md "Deferred capabilities").
- Cloud container: start dockerd with registry mirror mirror.gcr.io (Docker Hub answers 429); the live-stack (Caddy)
  tests may still error there and are left to CI.
- This handoff folder is deleted once epic #268 is done and its PRs are merged.
```
