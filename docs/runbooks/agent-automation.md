# Runbook — unattended agents (Claude Code + Antigravity)

Three host-side flows that hand work to a coding agent without a person in the loop, all driven by
`scripts/agent/` and `make agent.*`. They replace what Jules did (issue label → PR, scheduled
maintenance) with the CLIs already installed here: `claude -p` and `agy -p`.

| Flow | Trigger | Engine | Output |
|------|---------|--------|--------|
| Issue → PR | label `agent:claude` / `agent:agy` on an issue, polled every 10 min | the label | one branch, one PR, comment on the issue |
| Maintenance | weekly timer, one file per task in `scripts/agent/tasks/` | per task | a PR (`mode: pr`) or a local report (`mode: report`) |
| Second opinion | `make agent.review PR=<n>` (on demand) | `agy` by default | a local report, a PR comment only with `POST=1` |

Nothing here merges. Merging stays a per-PR human decision ([`CLAUDE.md`](../../CLAUDE.md) → "Protected `main`").

## Setup

1. **Sign `agy` in once**, interactively: run `agy`. Headless runs use the cached credentials and exit
   with `authentication required` when there are none.
2. `make agent.labels.setup` — creates `agent:claude`, `agent:agy`, `agent:running`, `agent:done`,
   `agent:failed` (idempotent; writes to the GitHub repo).
3. Edit `scripts/agent/allowed-users` if more than one person may drive agents.
4. **After the PR carrying this directory is merged**, from the *primary* checkout: `make agent.install`.
   It refuses to run from a worktree, because the units point at the checkout's scripts and a worktree
   is deleted after its PR merges. `make agent.status` shows the timers and today's log;
   `make agent.uninstall` removes them. The user manager only fires timers while a session exists; for
   a machine that must run them logged out, `sudo loginctl enable-linger $USER`.

Dry runs that change nothing: `make agent.poll DRY_RUN=1`, `make agent.maintenance DRY_RUN=1`.
Logs: `~/.local/state/erpify-agent/logs/`; reports: `~/.local/state/erpify-agent/reports/`.

## What an agent can and cannot do

The agent reads text a third party can write (an issue body, a diff) and holds a shell, so the control is
what it *cannot* do, not the wording of the prompt.

- **No git write, no `gh`, no network.** `claude` gets `--allowedTools`/`--disallowedTools`; `agy` gets
  `--settings scripts/agent/agy-settings.<profile>.json` (deny wins over allow). The agent edits files in a
  throwaway worktree; `scripts/agent/lib.sh` commits, pushes and opens the PR. Verified live: a `claude`
  run asked to `git push --dry-run` and `gh auth status` was denied both.
- **Protected paths.** A diff touching `.github/`, `.claude/`, `scripts/agent/`, any `CLAUDE.md`,
  `AGENTS.md`, `GEMINI.md` or `.env*` is not committed (the worktree is kept for inspection), nor one over
  60 files. Those are the paths where a hostile diff buys privilege.
- **Who may drive it.** An issue runs only when its author **and** the person who applied the label are in
  `allowed-users`; the repository is public and an issue body is the prompt. Anyone else gets the issue
  marked `agent:failed` with a comment.
- **One at a time.** A `flock` serialises all runs; a timer tick during a long run exits. Each engine call
  is bounded by `AGENT_TIMEOUT` (default 45 min). One issue per poll.
- **Outcome from git, not from the exit code.** `agy` soft-denies an unapproved tool and still exits 0, so
  success means "the worktree has a diff".

## Limits worth knowing

- **No stack, no local gates.** The agent cannot run `make php.quality` / `make pwa.quality` (no
  containers). Every PR says so and CI is the gate. Tasks needing a green local run are not a fit.
- **PRs spend CI minutes** (a branch push triggers the pipeline), which is why a maintenance task with an
  open PR from an earlier run is skipped rather than piled up.
- **`agy` is wired but not yet exercised live.** The CLI was not signed in when this was built; its
  settings keys follow the documented `permissions.allow/deny` schema and the `--settings` flag, and the
  first real run should be a read-only one (`make agent.review PR=<n>`).
- **The issue → PR path was exercised up to the push.** The commit, rejection and report paths ran
  against a stub engine and a real `claude`; the label swap, comment and `gh pr create` calls use GitHub
  and were not run end to end.
- **A green proves less than it looks.** Neither the protected-path list nor the allowlist judges whether
  the diff is *good*; review is the only control on that. A `deny` rule is only as complete as its list.

## Adding a maintenance task

Drop a Markdown file in `scripts/agent/tasks/` — a header (`engine`, `mode`, `branch-type`) and a prompt.
A `report` task is read-only and returns its report as the final answer; a `pr` task writes files only.
Try it with `make agent.maintenance ONLY=<name>` before the timer does.
