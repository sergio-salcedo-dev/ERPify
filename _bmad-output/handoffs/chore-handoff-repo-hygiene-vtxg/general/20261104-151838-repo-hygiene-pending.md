```
/bmad-help continue the repo-hygiene thread closed on 2026-11-04: three small decisions remain, nothing is in flight.

BRANCH: chore/handoff-repo-hygiene-vtxg (carries only this file; no PR, on purpose: a handoff-only PR is the PR count the new rule avoids). main is at 27be13cc.
DONE (all merged, worktrees and branches removed):
  - #1183 fix(api): keep the transport assertion's problem() under three returns
  - #1186 chore: keep tmp/ for scratch only and open PRs without asking, as few as the work needs
      * scripts/bmad-skills-sync.sh backs up to .backups/bmad-skills/ (gitignored via /.backups/), locks with flock on the target dir fd, keeps no state in tmp/
      * BmadSkillsSyncGuardrailGateTest pins it (one backup, nothing else kept, no tmp/ dir)
      * CLAUDE.md: tmp/ holds only what dies with the task; new section "Pull requests: as few as the work needs"; opening a PR needs no OK
  - #1188 chore(bmad): deleted spec-gh-1074-gdpr-erasure-residuals.md (done, PRs #1103/#1105 merged, issue #1074 closed; DW-14 stays open in deferred-work.md by design)
IN PROGRESS: nothing.
NEXT (decisions that are the user's, in this order):
  1. `/tmp.old/` in .gitignore (line 114, added by the user in d912cebc, left untouched on purpose). The user copied the old tmp/ content into tmp.old/ locally. Once they delete tmp.old/, drop that line in the PR that does something else; do not open a PR just for it.
  2. Branch creation: CLAUDE.md "Confirm branch creation & topology" (line ~371) still asks for explicit OK before creating a branch or splitting one task over several. The user said "PRs", not "branches", when relaxing the PR rule. Ask once whether branches may also be created without asking; if yes it is a one-line change in CLAUDE.md plus the memory note feedback-open-prs-without-asking-fewest-prs.md.
  3. Worktrees and PR owned by ANOTHER session, not touched here: PR #1189 (chore(agent): add unattended claude and agy automation, branch chore/agent-automation-yiuw, OPEN), worktrees agent-automation-yiuw, handoff-documents-spec-7kh2 (holds the documents-context handoff, other task) and eight selftest-* on docs/selftest-* at aaeb2982. Audit each (status, ancestor of origin/main, pushed) before any `make worktree.remove`; the selftest-* ones look like leftovers of a worktree-target test.
ARTIFACTS (re-read): CLAUDE.md ("Temporary / scratch files", "Pull requests: as few as the work needs", "Confirm branch creation & topology"), memory feedback-open-prs-without-asking-fewest-prs.md, scripts/bmad-skills-sync.sh
KEY PATHS: .gitignore, scripts/bmad-skills-sync.sh, api/tests/Unit/Gate/BmadSkillsSyncGuardrailGateTest.php, docs/claude-code-quickref.md (--force backup path), .claude/commands/deps-update.md and local-generate-md.md (scratch cleanup)
DECISIONS/CONSTRAINTS:
  - tmp/ is scratch only and stays as empty as possible; durable state goes in a named gitignored dir (.backups/ for the skills sync). A tracked file may reference tmp/ only as throwaway scratch the same step deletes.
  - One intent = one branch = one PR; review or CI findings are commits on the open PR. Merging still needs per-PR permission (/merge-when-green <n>); never --draft; never git add -A.
  - Delete this folder in the PR that settles the three items above (lifecycle rule for handoffs); do not open a PR just for the deletion.
```
