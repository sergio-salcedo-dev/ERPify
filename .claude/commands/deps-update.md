---
description: Consolidate the open dependabot "chore: bump" PRs into one branch and one PR, ecosystem-aware
argument-hint: "[--dry-run]   (default: inventory, then STOP for branch authorization)"
---

Batch the open dependabot PRs into a single branch. Dependabot opens **one PR per
dependency**, which is wrong whenever one bump spans several pin sites — those PRs
cannot go green individually, no matter how long you wait or how many times you
rerun them. `.github/dependabot.yaml` fires **weekly** across four ecosystems, so
this runs often.

Arguments: `$ARGUMENTS` may contain `--dry-run` to report only (change nothing,
create nothing).

## 1. Inventory

```bash
gh pr list --state open --author app/dependabot --limit 100 \
  --json number,title,headRefName,files,statusCheckRollup
```

If fewer than two PRs come back, say so and STOP — one bump needs no batching.

## 2. Classify by ecosystem — by path segment, never by substring

The ecosystem is the segment **immediately after `dependabot/`** in `headRefName`:

| Segment | Ecosystem | Applies to |
|---------|-----------|------------|
| `npm_and_yarn` | npm | `pwa/package.json` + `pwa/package-lock.json` |
| `github_actions` | GitHub Actions | `.github/workflows/*.yml` |
| `composer` | Composer | `api/composer.json` + `api/composer.lock` |
| `docker` | Docker base images | `api/Dockerfile`, `pwa/Dockerfile` |

**Never substring-match the ref.** `dependabot/github_actions/docker/bake-action-7.3.0`
is a *github_actions* bump whose action is published by `docker/`; matching on
`docker` sends it down the Dockerfile path and it silently does nothing.

Composer bumps may already arrive grouped (`… in the composer group across 1
directory`). Do not re-split a group — treat it as one unit.

## 3. Detect the multi-pin trap (the reason this command exists)

The trap is **multiple sub-paths of one action repository**, not multiple pin
sites. Dependabot keys a dependency on the full `<owner>/<repo>/<path>`, so it
already moves every occurrence of a single name in one PR — `actions/checkout`
sits at 11 sites and arrives as one PR, never eleven. Counting raw sites would
flag it, and the five other most-used actions here, as blocking when none of
them are. List the distinct names instead:

```bash
git grep -hoE "uses: [a-zA-Z0-9._-]+/[a-zA-Z0-9._/-]+@" -- .github/workflows/ \
  | sed 's/uses: //; s/@$//' | sort -u | awk -F/ '{print $1"/"$2}' \
  | sort | uniq -c | awk '$1>1{print $1" sub-paths: "$2}'
```

**A repository with more than one sub-path is red by construction** — each PR
moves its own step and leaves the siblings behind, and a job whose steps straddle
two releases dies with:

```
Loaded a configuration file for version 'X', but running version 'Y'
```

Do not investigate such a red as a regression; it is the split itself. All
sub-paths must reach the same SHA in one commit.

`github/codeql-action` is the worked example — `init` and `analyze` in
`codeql.yml`, `upload-sarif` in `ci.yml` — and it is **grouped in
`.github/dependabot.yaml` under `codeql-action`**, so it now arrives as a single
PR. It is also the only such repository in this tree today: if the one-liner
above prints anything else, that action needs a group of its own rather than a
hand-batch every release.

## 4. Report

Print a table: PR / ecosystem / files / CI conclusion / **batch-blocking?** (yes
when the multi-pin trap applies). Name every red and whether the split explains it.

`--dry-run` STOPS here.

## 5. STOP — branch authorization

Propose a branch (`chore/<slug>`, base `main`) and **wait for the user's explicit
OK**. Creating a branch or worktree unasked violates the hard rule in `CLAUDE.md`
→ Conventions → "Confirm branch creation & topology". Then:

```bash
make worktree.create BRANCH=chore/<slug>
```

Run every later command **from inside that worktree**.

## 6. Apply, per ecosystem

**GitHub Actions.** Move every pin site of the same action to the same SHA, keeping
the `# vX.Y.Z` comment. Verify the SHA against upstream rather than trusting the
Dependabot diff — the tag is usually annotated, so it resolves in two hops:

```bash
gh api repos/<owner>/<repo>/git/ref/tags/vX.Y.Z --jq '.object.sha, .object.type'
# type == "tag" (annotated) → resolve the tag object to its commit:
gh api repos/<owner>/<repo>/git/tags/<tag-sha> --jq '.object.sha'
```

The final commit SHA must equal the one being pinned.

**npm.** Edit the ranges in `pwa/package.json`, then reconcile with **one**
`npm install` from `pwa/`. Never merge the dependabot branches: each rewrites
`pwa/package-lock.json` wholesale and they conflict pairwise. Expect the resolved
lock to sit a patch *ahead* of what Dependabot pinned (the caret takes the newest
release), and expect peers to move packages nobody asked for — `react-dom@19.2.8`
peers on `react: ^19.2.8`, so `react` bumps even though its own range never changed.
Document both in the PR.

**Composer.** Edit the constraints in `api/composer.json`, then
`make composer c='update <pkg> … --with-dependencies'`. Follow with
`make composer.check.all` (platform-reqs, missing deps, unused).

**Docker.** Base images are pinned by digest, not tag — `FROM <image>@sha256:<digest>`
in `api/Dockerfile` (`dunglas/frankenphp`, `debian`) and `pwa/Dockerfile` (`node`).
Replace the digest from the PR title (`bump node from \`b46a10d\` to \`0473e7d\``);
the short form in the title is a prefix of the full digest in the diff.

## 7. Assert the lockfile gained nothing

For npm and Composer, compare the resolved lock against `main` and prove that
**every change is a version bump of a package already present**:

```bash
git show origin/main:pwa/package-lock.json   # compare package keys, not just versions
```

Report *added: 0, removed: 0* explicitly. A new package name is new ownership to
vet and belongs in the PR description, not in silence.

## 8. Gates — fresh run, printed exit code

Run only what the batch touched. zsh has **no `PIPESTATUS`**; capture properly:

```bash
make <target> > /tmp/gate.log 2>&1; echo "<target> EXIT=$?"
```

| Touched | Gates |
|---------|-------|
| npm | `make pwa.quality`, `make pwa.quality.dry-run` (what CI runs — check-only; the plain target *fixes* and can mask), `make pwa.test.unit` |
| npm, when `@playwright/test` moved | `cd pwa && ./node_modules/.bin/playwright test --list` — proves the new version parses `playwright.config.ts` and discovers every spec without needing browsers. Use the local binary: `npx playwright` may fetch a standalone package instead. |
| composer | `make php.quality`, `make ci.api` |
| docker | `make docker.up` (rebuilds), or lean on the `API Build (Docker)` CI job |
| github_actions only | nothing local — CI is the gate |

The full E2E suite is CI's job unless the user asks for it locally.

## 9. Security review

Dependency-only batches skip most of the `CLAUDE.md` checklist (no source touched);
say so in the PR rather than skipping silently. Always cover:

- **Supply chain** — the added/removed counts from step 7.
- **Action pinning** — still SHA-pinned with a version comment, verified upstream.
- **`npm audit` / `composer audit`** — for each advisory, decide **pre-existing vs
  introduced** by checking whether that package's version changed against `main`.
  Never report a pre-existing advisory as this batch's, and never let a new one pass
  as pre-existing. An unrelated pre-existing advisory is a follow-up PR, not scope creep.

## 10. Ship

Commit (`chore(deps): …`), **push the branch**, and open the PR listing the
superseded numbers. Per `CLAUDE.md` → "Finishing substantial work means committed
**and pushed**", a local commit is not a deliverable.

**Never merge** — that needs the user's explicit per-PR permission.

**Leave the dependabot PRs open.** Dependabot closes them itself on its next pass
once it sees the dependencies at target. Closing them by hand is only worth it if
they are still open days later.
