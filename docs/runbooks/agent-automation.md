# Runbook — unattended agents (Claude Code + Antigravity)

Three host-side flows that hand work to a coding agent without a person in the loop, all driven by
`scripts/agent/` and `make agent.*`. They replace what Jules did (issue label → PR, scheduled
maintenance) with the CLIs already installed here: `claude -p` and `agy -p`.

| Flow | Trigger | Engine | Output |
|------|---------|--------|--------|
| Issue → PR | label `agent:claude` / `agent:agy` on an issue, polled every 10 min | the label | one branch, one PR, comment on the issue |
| Maintenance | weekly timer, one file per task in `scripts/agent/tasks/` | per task | a PR (`mode: pr`) or a local report (`mode: report`) |
| Second opinion | `make agent.review PR=<n>`, on demand only | `agy` by default | a local report; a PR comment only with `POST=1` and a `y/N` |

Nothing here merges. Merging stays a per-PR human decision ([`CLAUDE.md`](../../CLAUDE.md) → "Protected `main`").

**What bounds a run.** Root `CLAUDE.md` lets an agent create a branch and open a PR without asking, so these flows
need no exception to it; what they add is a ceiling. An `agent:*` label applied by an account in `allowed-users`
triggers one branch and one PR for that issue, and `make agent.install` one branch per task per run, skipped while
that task still has an open PR. Names and base are fixed by the scripts (`<type>/agent-<slug>-<suffix>` off
`origin/main`).

## Setup

1. **Sign `agy` in once**, interactively: run `agy`. Headless runs use the cached credentials; per its
   documentation an unauthenticated run exits with an `authentication required` error rather than hanging.
2. `make agent.labels.setup` — creates `agent:claude`, `agent:agy`, `agent:running`, `agent:done`,
   `agent:failed` (idempotent; writes to the GitHub repo).
3. Edit `scripts/agent/allowed-users` if more than one person may drive agents.
4. **After the PR carrying this directory is merged**, from the *primary* checkout on a clean, current `main`:
   `make agent.install`. It refuses to run from a worktree, because the units point at the checkout's scripts and a
   worktree is deleted after its PR merges. The timers execute whatever the primary has checked out, so
   keep it on `main` (`git pull`) or the scripts that run are another branch's. `make agent.status` shows the timers
   and today's log; `make agent.uninstall` removes them. The user manager only fires timers while a session
   exists; for a machine that must run them logged out, `sudo loginctl enable-linger $USER`.
5. `git push` from a timer has no `ssh-agent`: use a key without a passphrase prompt, or a push failure marks the
   issue `agent:failed`.

Dry runs that change nothing: `make agent.poll DRY_RUN=1`, `make agent.maintenance DRY_RUN=1`.
`make agent.selftest` runs the pipeline against a stub engine (real throwaway worktrees, nothing pushed).
Logs: `~/.local/state/erpify-agent/logs/`; reports: `~/.local/state/erpify-agent/reports/`.

## What an agent can and cannot do

The agent reads text a third party can write (an issue body, a diff), so the control is what it *cannot* do, not
the wording of the prompt. Each point below was exercised against the real `claude` CLI unless marked otherwise.

- **No shell, no git, no `gh`, no MCP.** The agent holds `Read`, `Glob`, `Grep` and, for an edit task, `Edit` and
  `Write` scoped to the worktree (`./**`). Every read-only git verb was measured able to write an arbitrary
  file (`--output=<path>`), so no Bash rule short of "none" is a boundary. A live run asked to write outside the
  worktree was denied for both an unrelated path and the primary checkout, and one asked for a shell said so. `Write`
  without that scope **was** measured writing an absolute path outside the worktree, which is why it is scoped.
- **The script publishes, not the agent.** `lib.sh` commits, pushes and opens the PR with hooks and fsmonitor
  disabled. The agent's commit message and PR body are sanitised (no trailers, no auto-close keywords, no live
  `@mention`) and scanned for token-shaped strings.
- **Refused diffs.** Nothing is committed when the diff touches a path that executes — `.github/`, `.claude/`,
  `scripts/`, `Makefile`, `make/`, compose and Docker files, `composer.json` / `package.json` and their locks,
  `api/tools/`, any `bootstrap.php`, any `CLAUDE.md` / `AGENTS.md` / `GEMINI.md`, `.env*`, `.mcp.json`,
  agent directories — or adds a symlink, a submodule or a newly executable file, exceeds 60 files or 2 MB, or carries
  a token-shaped string. Paths are read NUL-delimited: git quotes a non-ASCII name, and the quoted form matched no
  prefix, so `.github/workflows/é.yml` passed an earlier version of this check. The worktree is kept for inspection.
- **Scrubbed run.** The engine starts under `env -i` with only `HOME`, `PATH`, the locale and its own API key; the
  project's hooks and every MCP server are off (`--strict-mcp-config`, `disableAllHooks`); the run lock is closed in
  its descriptors; reads of `~/.ssh`, `~/.config/gh`, `~/.aws`, `~/.claude`, `~/.gemini` and the agent state
  directory are denied. A run whose JSON shows zero turns is a failure: a blocking hook makes `claude` exit 0 with
  `is_error: false` and the hook's message as the "result".
- **Who may drive it.** An issue runs only when its author **and** the person who applied the label are in
  `allowed-users` (an empty login never matches); the repository is public and an issue body is the prompt. Anyone
  else gets the issue marked `agent:failed` with a comment. The issue text sits between random per-run markers.
- **One at a time, and recoverable.** A `flock` serialises all runs (the weekly run waits for it, an interactive
  call exits 75, the poller exits quietly). Each engine call is bounded by `AGENT_TIMEOUT` (default 45 min). An
  issue is claimed oldest first, one per tick; a run that dies after the claim is moved to `agent:failed` by an `EXIT`
  trap, and an orphaned `agent:running` is swept at the next tick.
- **A PR review is shell-less and sandboxed from the PR.** The checkout is the PR head, so every file that configures
  an agent (`.claude/`, `.mcp.json`, the instruction files, agy's directories) is replaced by `origin/main`'s before the
  engine starts, and the diff is written to `tmp/pr.diff` by the script. `--post` needs a terminal and a `y/N`.

## Limits worth knowing

- **The units carry `NoNewPrivileges` and no other sandbox directive.** `PrivateTmp=yes` was measured breaking every
  `git fetch` over SSH (`Bad owner or permissions on /etc/ssh/ssh_config.d/...`): the first labelled issue failed in three
  seconds. A sandbox directive is only safe here after `systemd-run --user --pipe --wait -p <directive> git ls-remote origin main`
  still succeeds. After changing a template, run `make agent.install` again.

- **Read is a deny-list, not a jail.** A path nobody listed is readable, and the agent's text reaches a public PR through
  the script. The token scan catches common credential formats, not every secret. Real isolation would be a container or
  a dedicated OS user; this is not that.
- **CI runs the pushed branch before any human reads it**, as it does for any contributor branch, including the tests and
  application code an agent adds. The protected list keeps the agent away from the files that decide *how* CI runs.
- **No stack, no local gates.** The agent cannot run `make php.quality` / `make pwa.quality` (no containers). Every PR says
  so and CI is the gate.
- **Every PR the automation opens spends a full CI run, so the open count is capped.** `AGENT_MAX_OPEN_PRS` (default 3)
  is the most agent PRs open at once, across the issue poller and the maintenance tasks. At the cap the poller leaves the
  issue labelled and queued, and a `pr` task is skipped until someone merges or closes one. `0` pauses PR creation while
  `report` tasks keep running. An agent PR is recognised by the marker the script stamps in its body, not by its branch
  name, and an unreadable GitHub counts as "cap reached". To raise it for the timers, run
  `systemctl --user edit erpify-agent-poll.service` and add `Environment=AGENT_MAX_OPEN_PRS=5`, and the same for the
  maintenance unit. `make agent.status` prints the open PRs against the cap and, for the last 30 days, how many merged and
  how many were closed unmerged: the latter ran CI for nothing, and is the number to read before adding a task. No minute
  figure is quoted because none has been measured; read one off the first PR the automation opens.
- **A run is not free.** A trivial read-only `claude` call inside this repo (two turns) reported about $0.60 of API-equivalent
  usage, mostly the large `CLAUDE.md` context. A 45-minute edit task costs far more.
- **`agy` is wired from its documentation and not yet exercised live.** Its `permissions` keys, the `--settings` flag and the
  "unapproved tool is soft-denied and exits 0" behaviour are as documented, unobserved. The first real run should be
  read-only: `make agent.review PR=<n>`. The stub-engine self-test and the live `claude` runs cover the pipeline, not `agy`.
- **Not run end to end:** the label swap, the issue comment and `gh pr create` use GitHub and have not been exercised with a
  real labelled issue.
- **Failed and rejected runs keep their worktree and branch** (`make worktree.list`, then `make worktree.remove NAME=<dir>`);
  retries mint a new suffix each time.
- **A green proves less than it looks.** Neither the protected list nor the allowlist judges whether the diff is *good*;
  review is the only control on that.

## Checking a run

- `make agent.status`: timers, open agent PRs against the cap, and the tail of today's log.
- The full log is `~/.local/state/erpify-agent/logs/<YYYYMMDD>.log`, and report-mode output goes to
  `~/.local/state/erpify-agent/reports/`.
- An issue left `agent:failed` carries a generic reason in its comment; the detail (paths, engine output) is only in the log.

## Adding a maintenance task

Drop a Markdown file in `scripts/agent/tasks/` — a header (`engine`, `mode`, `branch-type`) closed by `---`, and a prompt.
A `report` task is read-only and returns its report as the final answer; a `pr` task writes files only. Try it with
`make agent.maintenance ONLY=<name>` before the timer does.
