---
title: 'Accepted-risk gate (#863): paragraph-scoped tag, offline structural check, live-issue-state CI check'
type: 'feature'
created: '2026-08-27'
status: 'done'
review_loop_iteration: 1
context: ['{project-root}/docs/rules/testing.md']
baseline_commit: '4c830d699a9bac36601673c3bbd34ca43bf36666'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Twice, a residual risk was resolved "accept, don't fix" with its rationale written only in prose (a PHP docblock or a spec markdown file), with no open, currently-tracked issue backing it — invisible to audit, and nothing catches a tag whose issue later closes silently.

**Approach:** A paragraph-scoped `@accepted-risk #<issue>` tag; an offline PHPUnit gate (16th `php.lint.*` member, no network) validating tag grammar and paragraph co-location via a content floor, not semantic disposition-detection; a small independent GitHub Actions job checking live issue state over the whole tree. Retroactively tag `#860` and `#861`. Trail: issue #863 (body + 2 comments), this session's Winston/ChatGPT debate, and a code review that closed the remaining grammar/semantics gaps (2026-08-27).

## Boundaries & Constraints

**Always:**
- Grammar: `/@accepted-risk[ \t]+#[1-9][0-9]*/` — reject `@accepted-risk#123` (no space), `#0`, `#0123` (leading zero). One tag = one reference; the same issue may be tagged in multiple paragraphs/files (dedup happens only at live-lookup time, never as a structural violation).
- **The gate never identifies "the disposition sentence" semantically** — it checks a purely structural proxy: the tag's paragraph must contain a minimum floor of non-tag prose (propose ≥15 non-whitespace characters besides the tag itself — mirrors `MIN_TRAILER_CHARS`/`MIN_RECORD_CHARS` in `scripts/adversarial-pass-check.sh`). A tag floating alone in its own paragraph, with no accompanying rationale, fails on that floor — never on "wrong sentence," since the gate can't identify one.
- Paragraph boundaries — PHPDoc: strip `/** */` + per-line `*` prefix; a blank comment line is the only boundary (v1 has no PHPDoc list-item unit — no real instance needs it; revisit if one appears). Markdown: blank line, list item, or heading is a boundary. **Fenced code blocks are excluded from scanning in both formats** — a tag inside a ```` ``` ```` example must never become a live dependency.
- Multiple tags in one paragraph are each independently valid. Live-state lookup deduplicates by issue number first, so `#860` tagged twice yields exactly one `gh issue view`.
- A tag whose issue number cannot be resolved by GitHub (deleted/never existed) is a **GATE ERROR**, distinct from `closed` — the reference is structurally valid but unverifiable, not proof the risk is unresolved.
- v1 treats GitHub's shared issue/PR number namespace as the tracking namespace — no type discrimination between an issue and a PR number. State this as a documented v1 limit, not silently.
- Live-state job: whole-tree scan every run (not diff-only) — a diff-only scan misses an issue closing after its tagging PR already merged, and the cost is negligible at today's count (2). Calls `gh issue view <n> --repo <owner>/<repo> --json state` explicitly (no implicit checkout-relative repo). Exit code / JSON parse failure → GATE ERROR; only `state: CLOSED` is a policy failure. Never treat run-to-run open state as a transactional guarantee — it is an observation at check time.
- Structural half: `api/tests/Support/AcceptedRiskTags.php` (scanner, no test assertions) + `api/tests/Unit/Gate/AcceptedRiskTagGateTest.php` + `AcceptedRiskTagRulesGateTest.php`, wired as `php.lint.accepted-risk` into `php.quality`. Zero network. Scan scope: `api/src/**/*.php` docblocks + `_bmad-output/implementation-artifacts/spec-*.md`. `docs/adr/*.md` out of scope this slice.
- Live-state half: independent GitHub Actions job, `permissions: {contents: read, issues: read}`. Does not reuse `scripts/adversarial-pass-check.sh`'s PR-interception functions.
- Phrase-detection stays discovery-only (never pass/fail). Seed list, explicitly incomplete: EN — "accepted risk", "accept the risk", "risk accepted", "accept, don't fix", "won't fix", "residual risk accepted"; ES — "riesgo aceptado", "se acepta el riesgo", "riesgo residual aceptado", "no se corrige".
- Only the two Gate **test** files get an `api/.artifact-gate-placement` entry (`home`) — `Support/` engine classes are outside that registry's scope (confirmed against the existing tree: no `tests/Support/*.php` engine file is classified there today).
- Retro-tag `#860` inside its existing paragraph (`NotifyLockedIdentities.php`, the "Accepted residual..." sentence). Retro-tag `#861` inside the "third vector" paragraph in `spec-fix-adversarial-pass-hook-cwd.md`, **adding a short narrative reference** ("— tracked in issue #861, not only here"), mirroring `#860`'s phrasing — not a bare tag with no prose context, since that file names no issue anywhere today.

**Never:**
- No hand-classified registry file (`.accepted-risk-policy`) — no per-item "should this be watched" judgment call exists to check completeness/staleness of; the tag is the declaration.
- No cloning of `adversarial-pass-check.sh`'s PR-open-detection plumbing.
- No semantic/NLP matching of an issue's content against citing text, no automatic issue creation/reopening/"fixed" inference, no retries around the race between scan and live state.
- No enforcement inside `docs/adr/*.md` this slice.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Well-formed tag, non-trivial paragraph, issue open | `@accepted-risk #900` + ≥15 chars of prose in-paragraph | Structural gate passes; CI job passes | N/A |
| Tag alone in its own paragraph | Tag only, blank line before real rationale | Structural gate FAILS (content floor) | Names file:line and the floor it missed |
| Tag inside fenced code block | ```` ```php\n@accepted-risk #1\n``` ```` in a spec | Ignored entirely | N/A |
| Referenced issue closed | Valid tag, `state: CLOSED` | Live CI job FAILS | Distinct message from a transport/gate error |
| Issue does not exist | Tag references a deleted/invalid number | GATE ERROR | Distinct from `closed` |
| `gh` transport error | Non-zero exit, no parsable JSON | GATE ERROR | Never silently becomes a policy failure |
| Same issue tagged in 2 files | `#860` in `A.php` and `B.md` | Both structural entries pass; ONE `gh issue view` call | N/A |
| No tags exist anywhere | Clean tree | Structural gate PASSES | Zero tags is not a violation |

</frozen-after-approval>

## Code Map

- `api/tests/Support/AcceptedRiskTags.php` -- new: layered scanner (source extraction → paragraph segmentation → tag extraction); returns `AcceptedRiskTag{issueNumber, sourceFile, line, paragraphStartLine, paragraphEndLine}` value objects so assertions never re-parse text
- `api/tests/Unit/Gate/AcceptedRiskTagRulesGateTest.php` -- new: falsifies grammar + paragraph rules against fixtures under `api/tests/Unit/Gate/Fixture/AcceptedRiskTags/` (mirrors `ScheduleConsumption`'s fixture convention) — tag+rationale same paragraph (pass), tag-only paragraph (fail on floor), tag in fenced code (ignored), malformed grammar variants (rejected), multiple tags one paragraph (pass), same issue two files (dedup)
- `api/tests/Unit/Gate/AcceptedRiskTagGateTest.php` -- new: asserts every real tag in `api/src` + `spec-*.md` satisfies the rules — asserts properties, never a tag count (the inventory legitimately grows)
- `make/php-quality.mk` -- add `php.lint.accepted-risk`, wire into `php.quality`
- `api/.artifact-gate-placement` -- classify the two new **Gate test** files as `home`
- `api/src/Iam/Identity/Application/NotifyLockedIdentities.php` -- retro-tag `#860`'s existing paragraph (~line 88)
- `_bmad-output/implementation-artifacts/spec-fix-adversarial-pass-hook-cwd.md` -- retro-tag `#861`'s "third vector" paragraph (~line 58) with the added narrative reference
- `.github/workflows/accepted-risk-live-state.yml` -- new, built last: dedups tags from the whole tree, `gh issue view --repo <owner>/<repo> --json state` per issue

## Tasks & Acceptance

**Execution (build order matters — grammar/fixtures before the live workflow):**
- [x] `AcceptedRiskTags.php` -- implement grammar + paragraph segmentation + content-floor check -- core logic, kept out of assertions for falsifiability
- [x] `AcceptedRiskTagRulesGateTest.php` + fixtures -- falsify every rule in the I/O matrix before trusting it on real code
- [x] `AcceptedRiskTagGateTest.php` -- real-tree assertion using the rules proven above
- [x] `make/php-quality.mk` + `php.quality` -- add and wire `php.lint.accepted-risk`
- [x] `api/.artifact-gate-placement` -- classify both Gate test files
- [x] `NotifyLockedIdentities.php` -- add `@accepted-risk #860` inline within the existing paragraph (not its own line -- see Design Notes)
- [x] `spec-fix-adversarial-pass-hook-cwd.md` -- add `@accepted-risk #861` + narrative reference inside the "third vector" paragraph
- [x] `.github/workflows/accepted-risk-live-state.yml` -- independent live-state job, last

**Acceptance Criteria:**
- Given a tag alone in its own paragraph with real rationale only in a different paragraph, when the structural gate runs, then it fails naming the paragraph's content floor — never a claim about "the disposition sentence." -- **Verified**: `AcceptedRiskTagRulesGateTest::aTagAloneInItsOwnParagraphFailsTheContentFloor`, green.
- Given `#860`'s and `#861`'s retro-tagged paragraphs, when the structural gate runs, then both pass. -- **Verified**: `make php.lint.accepted-risk`, both `AcceptedRiskTagGateTest` methods green over the real tree (confirmed via a throwaway diagnostic script that exactly 1 tag was found in each scope, at the expected files/lines, before it was deleted).
- Given a tag inside a fenced code block, when the structural gate runs, then it is ignored. -- **Verified**: `AcceptedRiskTagRulesGateTest::aTagInsideAFencedCodeBlockIsIgnoredEntirely`, green.
- Given `#860` tagged in two files, when the live-state job runs, then exactly one `gh issue view` call is made for it. -- Verified by construction/code-reading only (`sort -un` dedup before the loop in the workflow) -- no live CI run was triggered in this pass.
- Given a tag referencing a nonexistent issue, when the live-state job runs, then it reports a GATE ERROR distinct from a closed-issue failure. -- Verified by construction only (non-zero `gh issue view` exit routes to the `else` branch, distinct message) -- not exercised against the live GitHub API in this pass.
- Given a `gh` transport error, when the live-state job runs, then it reports a GATE ERROR, never a policy violation. -- Same construction-only verification as above; the workflow's live behavior is unverified until it runs on a real pull request.

## Spec Change Log

- 2026-08-27 (review_loop_iteration 1): External code review found the "same paragraph as the disposition-declaring sentence" rule undecidable by a non-semantic scanner. Resolved by replacing it with a mechanical content-floor proxy (tag's paragraph must carry non-tag prose past a minimum length), formalizing tag grammar, paragraph boundaries (incl. fenced-code exclusion), multi-tag/dedup semantics, nonexistent-issue handling, and moving whole-tree scanning from "Ask First" to a decided default. KEEP: no registry file, no `adversarial-pass-check.sh` reuse, no semantic matching, discovery-only phrase list — all reconfirmed by the review.

## Design Notes

Three-layer scanner (extraction → paragraph segmentation → tag extraction) keeps parsing and policy separate, so `AcceptedRiskTagRulesGateTest` can falsify the rules independently of the real-tree gate. The content-floor number (≥15 chars) is a starting default, tunable like every other `MIN_*` constant in this repo's process scripts — not exact science.

**Discovered during implementation, not anticipated by the frozen spec: `make php.quality`'s PHP-CS-Fixer pass blank-line-isolates any PHPDoc line whose first token is `@word`** (the same treatment as `@param`/`@return`/`@see`). A tag written as its own leading-`@` line -- the natural first attempt, and how `#860` was first written -- got silently detached from its rationale paragraph by the mandatory formatter the moment `php.quality` ran, which would have made the content-floor check fail forever on a genuinely well-formed declaration. Fix, applied without touching the frozen grammar: a PHP tag is always written inline, at the end of an existing prose line, never as its own line -- confirmed with an isolated `php.cs-fixer` run showing 0 files changed once inline. Markdown specs (`spec-*.md`) have no such fixer and are unaffected; `#861` was already written inline. Documented at `AcceptedRiskTags`'s class docblock so a future PHP tag isn't written the naive way and silently broken by the next `php.quality` run.

## Verification

**Commands (all run fresh, exit codes read from the actual invocation, not memory):**
- `make php.stan c='<changed files>'` -- exit 0, no errors.
- `make php.lint.accepted-risk` -- exit 0 (6 + 2 tests green).
- `make php.lint.gate-placement` -- exit 0 (both new Gate test files classified `home`, no completeness/staleness drift).
- `make php.quality` -- exit 0 on the third fresh run (first run caught 4 PHPStan findings and a PHPMD complexity/else violation on files this task authored, since fixed; second run caught 3 line-length violations the auto-fixer's own merge introduced, also fixed).

**Not yet verified (residual, flagged for the human/orchestrator before merge):**
- The live-state workflow (`.github/workflows/accepted-risk-live-state.yml`) has not been exercised against the live GitHub API or a real pull request in this pass -- neither the `gh` CLI (broken local keyring token) nor the GitHub MCP server (expired token) could authenticate in this session either. Confirm on the actual PR that (a) `#860`/`#861` both report OPEN, (b) a deliberately-closed test issue reports a policy failure, and (c) `gh issue view <closed-id> --repo sergio-salcedo-dev/ERPify --json state` behaves as the script assumes.

**Code review, round 1 (Blind Hunter + Edge Case Hunter, no shared context, 2026-08-27):** 21 findings, deduplicated to 17. Patched (verified green after, `make php.quality` exit 0): live-state workflow now fails loud rather than silently passing when its scan roots are missing; its bash extractor is now scoped to PHPDoc blocks for `.php` files (matching the offline gate's own scope exactly, closing a real asymmetry where a tag outside a docblock was live-tracked but never structurally validated); fenced-code exclusion in both scanners now requires the SAME delimiter (` ``` `/`~~~`) to close a fence, never either; the content floor now counts `mb_strlen`, not bytes; `assertEveryTagValid()` now collects every violation before one assertion instead of failing on the first; the arbitrary 32-char tail window that could silently truncate an oversized issue number into a wrong shorter one is gone; the bash and PHP grammars now have a literal-text parity test (`AcceptedRiskTagRulesGateTest::theLiveStateWorkflowsBashGrammarTextHasNotDriftedFromThisGate`); the workflow's `exit "$failures"` wraparound risk is fixed; added a daily `schedule` trigger so a closed issue is caught within a day even with no new pull request. Cosmetic: `CoversNothing` imported rather than inline-FQCN in both gate tests. Rejected: the content floor's gameability (already conceded in Design Notes, not new information); the unrelated `api/config/reference.php` diff (known benign `cache:warmup` autogen artifact, unrelated to this feature); a theoretical false-positive from a `/** */`-shaped run inside a PHP string literal (would need full tokenization to close -- out of proportion to the threat). Not fixed, called out above instead: the live-state workflow's actual GitHub API behavior remains unverified in any session so far.

## Suggested Review Order

**Design intent (the whole feature in one file)**

- Why two mechanisms, why whole-tree, why not `adversarial-pass-check.sh`, what's still unverified.
  [`accepted-risk-live-state.yml:1`](../../.github/workflows/accepted-risk-live-state.yml#L1)

- The scan-scope/fence-parity fix from code review: docblock-only for `.php`, same-delimiter fence close.
  [`accepted-risk-live-state.yml:69`](../../.github/workflows/accepted-risk-live-state.yml#L69)

- Fail-loud guard for missing scan roots, replacing a silent-pass failure mode found in review.
  [`accepted-risk-live-state.yml:102`](../../.github/workflows/accepted-risk-live-state.yml#L102)

- Daily re-check trigger, closing the "issue closes with no new PR" gap.
  [`accepted-risk-live-state.yml:29`](../../.github/workflows/accepted-risk-live-state.yml#L29)

**Tag grammar & paragraph scanner (the offline half's engine)**

- Three-layer scanner: extraction → paragraph segmentation → tag extraction, with the content-floor rationale.
  [`AcceptedRiskTags.php:37`](../../api/tests/Support/AcceptedRiskTags.php#L37)

- `scanFile` entry point most callers use.
  [`AcceptedRiskTags.php:46`](../../api/tests/Support/AcceptedRiskTags.php#L46)

- PHPDoc paragraph boundary: blank-comment-line only, no list-item unit in v1.
  [`AcceptedRiskTags.php:140`](../../api/tests/Support/AcceptedRiskTags.php#L140)

- Markdown paragraph boundary, with the same-delimiter fence fix and table/inline-code exclusion.
  [`AcceptedRiskTags.php:201`](../../api/tests/Support/AcceptedRiskTags.php#L201)

- Value object carrying position so a caller never re-parses source to report where a tag lives.
  [`AcceptedRiskTag.php:17`](../../api/tests/Support/AcceptedRiskTag.php#L17)

- Value object for one logical paragraph — no `sourceFile` by design; see the gate test's per-file iteration.
  [`AcceptedRiskParagraph.php:13`](../../api/tests/Support/AcceptedRiskParagraph.php#L13)

**Structural gate (offline half's assertions)**

- Falsifies every rule against fixtures before the real-tree gate trusts them.
  [`AcceptedRiskTagRulesGateTest.php:25`](../../api/tests/Unit/Gate/AcceptedRiskTagRulesGateTest.php#L25)

- The content-floor fixture proving a tag alone in its own paragraph fails, never on "wrong sentence."
  [`AcceptedRiskTagRulesGateTest.php:42`](../../api/tests/Unit/Gate/AcceptedRiskTagRulesGateTest.php#L42)

- Cross-language grammar-parity pin added in review — the bash and PHP grammars can't silently drift apart.
  [`AcceptedRiskTagRulesGateTest.php:123`](../../api/tests/Unit/Gate/AcceptedRiskTagRulesGateTest.php#L123)

- Real-tree gate over `api/src` + specs, asserting properties never a count.
  [`AcceptedRiskTagGateTest.php:29`](../../api/tests/Unit/Gate/AcceptedRiskTagGateTest.php#L29)

- Collects every violation before one assertion, fixed in review from a fail-fast version that hid all but the first.
  [`AcceptedRiskTagGateTest.php:53`](../../api/tests/Unit/Gate/AcceptedRiskTagGateTest.php#L53)

**Wiring & registries**

- New `php.lint.accepted-risk` target, the 16th `php.lint.*` member, wired into `php.quality`.
  [`php-quality.mk:429`](../../make/php-quality.mk#L429)

- Both new gate test files classified `home` — `Support/` engine classes deliberately excluded.
  [`.artifact-gate-placement:114`](../../api/.artifact-gate-placement#L114)

**Retroactive tagging (the two real instances that motivated #863)**

- `#860`'s existing accepted-risk paragraph, now machine-checkable.
  [`NotifyLockedIdentities.php:91`](../../api/src/Iam/Identity/Application/NotifyLockedIdentities.php#L91)

- `#861`'s paragraph, previously naming no issue anywhere in the file — tag and narrative reference both new.
  [`spec-fix-adversarial-pass-hook-cwd.md:58`](spec-fix-adversarial-pass-hook-cwd.md#L58)

**Peripherals**

- Fixtures the rules gate falsifies against — worth a skim, not a deep read.
  [`Fixture/AcceptedRiskTags/`](../../api/tests/Unit/Gate/Fixture/AcceptedRiskTags/)
