---
description: Consolidate the open dependabot "chore: bump" PRs into one branch and one PR, ecosystem-aware
argument-hint: "[--dry-run]   (default: inventory, then STOP for branch authorization)"
---

Batch the open dependabot PRs into a single branch. Dependabot opens **one PR per
dependency**, which is wrong whenever one bump spans several pin sites, or whenever
two packages are coupled through an API neither declares — those PRs cannot go green
individually, no matter how long you wait or how many times you rerun them.
`.github/dependabot.yaml` fires **weekly** across four ecosystems, so this runs often.

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

## 3. Detect the coupling traps (the reason this command exists)

Three classes. **3A is knowable before you run anything** (a static read of our own
tree); **3B needs an A/B against the red**, because the coupling lives in vendor code and
neither package's constraints refuse it; **3C is not a split at all** — it is real work the
bump demands, and it survives batching. 3A and 3B block the batch; 3C changes its nature.
Establish which one you have — none of the three is grounds to stop reading a red.

### 3A — Multi-pin: several sub-paths of one action repository

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

### 3B — Coupled packages: one reaches into another's internals

Two packages of the same ecosystem where one calls the other's **non-public** API.
Dependabot keys each as its own dependency and opens one PR each, so the reached-into
package moving alone fatals the reacher.

**This week's pair is a clean A/B, and that is the evidence:**

| PR | what actually moved | `php.rector.dry-run` |
|----|---------------------|----------------------|
| #738 | `phpstan/phpstan` 2.2.5→2.2.8 **alone**, rector held at 2.5.7 | `MissingPrivatePropertyException` ×2 |
| #739 | **both**, co-moved | fatal **absent** (0) — red only on the rewrites, see 3C |

```
PHP Fatal error: Uncaught Rector\Exception\Reflection\MissingPrivatePropertyException:
Property "$container" was not found in "PHPStan\Parser\RichParser" class
```

Rector reaches PHPStan's container through `PrivatesAccessor` in
`PHPStanContainerMemento::removeRichVisitors()`; PHPStan 2.2.8 replaced that private
property. **#739 co-moves because Composer forces it** — rector 2.6.1 raises its floor to
`phpstan/phpstan: ^2.2.6` (2.5.7 asked `^2.2.2`), which 2.2.5 cannot satisfy. Read a
dependabot PR's own diff before believing its title: #739 is titled as a rector bump and
moves two packages.

**Where the static checks stand — the asymmetry is the point.** A declared requirement is
a *lower bound*, so rector 2.5.7's `^2.2.2` cheerfully **admits** the breaking phpstan
release; only a `conflict` entry would refuse it, and neither package carries one (rector
conflicts only with its own `rector-*` siblings, phpstan only with `phpstan-shim`). So in
the direction that breaks you, `composer why-not phpstan/phpstan 2.2.8` reports nothing;
in the direction that does not, `composer why-not rector/rector 2.6.1` names the pairing
outright, because that floor moved. Run it **both ways** before concluding there is no
coupling — one silence is not an answer.

**Confirm by A/B, never by classifying the error string.** Hold one, move the other, then
move both: if the red dies only when they move together, that is the coupling. The error
text is a hint with no coverage guarantee — reflection / private-property /
missing-internal-class are the shapes *this* break took; a coupling through a constructor
signature surfaces as `ArgumentCountError`/`TypeError`, and one through changed behaviour
produces **no red at all**. Nor is the pair necessarily same-week: `cooldown:
default-days: 7` plus step 10's "leave the dependabot PRs open" routinely straddles weeks.
A red that matches the signature still deserves reading — #738's is itself an upstream BC
break in a *patch* release.

**Reading the upstream fix: a 404 is a prompt, never a conclusion.** List the reaching
file's directory at **both** tags, then read whatever replaced it:

```bash
gh api "repos/rectorphp/rector/contents/src/DependencyInjection/PHPStan?ref=2.5.7" --jq '.[].name'
gh api "repos/rectorphp/rector/contents/src/DependencyInjection/PHPStan?ref=2.6.1" --jq '.[].name'
```

Measured: `PHPStanContainerMemento.php` at 2.5.7, `RichParserFactory.php` at 2.6.1 — the
file was **replaced, not abandoned**, and only reading the replacement shows it builds its
parser through a public constructor. **The practice is not over:** at 2.6.1
`RectorParser.php` still holds two `PrivatesAccessor` calls on `RichParser::$parser`. Treat
the pair as inseparable from here on rather than reading one repaired reach as the end of
the risk.

**A group in `.github/dependabot.yaml` is the durable fix**, exactly as for
`codeql-action`. The composer lane declares no `groups:` today, so the pair re-splits every
week it moves.

### 3C — Not a split: a fixer bump that ships new rules

The rest of #739's red is neither the coupling nor a regression: `php.rector.dry-run` exits
2 with `39 files would have been changed`, because rector 2.6.x binds its Symfony/PHPUnit
rules to the installed package version (*composer-based* sets) and thereby activates rules
that already existed at 2.5.7. That red **survives any batching** — it is real work, and it
turns a dependency-only batch into a source-touching PR, so step 9's checklist exemption
falls away.

Size it from the dry-run log, and **do not reach for `grep -A1`**: Rector prints one ` * `
line per distinct rule *per file* with no blank line after the header, so `-A1` keeps only
each block's first rule and drops the rest in silence. Measured on this log it reported 39
applications over 7 rules — the truncated total landing exactly on the *file* count, which
reads like corroboration — against a true 47 over 8, losing `CommandHelpToAttributeRector`
entirely.

```bash
awk '/files with changes/{f=1} /files would have been changed/{f=0} f' <log> \
  | grep '^ \* ' | sort | uniq -c | sort -rn
```

```
18 RenameMethodRector             6 CommandConfigureToAttributeRector
 8 ParamAndEnvAttributeRector      4 PushRequestToRequestStackConstructorRector
 6 CommandHelpToAttributeRector    2 SimplifyUselessVariableRector
 2 AllowMockObjectsForDataProviderRector      1 CreateStubInCoalesceArgRector
```

47 rule applications across 39 files. `make php.rector.dry-run` forwards `c=`, so
`c='--rules-summary'` asks Rector for the tally instead of parsing its prose.

Whether to take those rewrites wholesale, take them selectively (a skip list in
`api/tools/rector/rector.php`), or split them off is the **user's** call, not yours.

## 4. Report

Print a table: PR / ecosystem / files / CI conclusion / **batch-blocking?** (yes when
3A or 3B applies). Name every red and which class it is — the multi-pin split (3A), the
private-API coupling (3B), or real work the bump demands (3C) — and say how you told them
apart. For a suspected 3B, the A/B is the answer, not the error text.

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

**`npm install` moves nothing whose locked version already satisfies its range**,
so a bump needing no manifest edit is one this step drops in silence. `@types/react`
and `@types/react-dom` are pinned `^19`, which `19.2.17` and `19.2.18` both satisfy:
there is no range to edit, the install keeps the locked version, and the batch ships
naming two bumps it does not contain. Nothing goes red — the PR simply asserts a
version that is not in the lock. Step 1's `files` list is the tell: **a dependabot PR
touching only `package-lock.json` is one of these.** Move those explicitly, after the
install:

```bash
npm update <pkg> …   # respects the range, takes the newest version it allows
```

**Composer.** Edit the constraints in `api/composer.json`, then
`make composer c='update <pkg> … --with-dependencies'`. Follow with
`make composer.check.all` (platform-reqs, missing deps, unused).

**Docker.** Base images are pinned by digest, not tag — `FROM <image>@sha256:<digest>`
in `api/Dockerfile` (`dunglas/frankenphp`, `debian`) and `pwa/Dockerfile` (`node`).
Replace the digest from the PR title (`bump node from \`b46a10d\` to \`0473e7d\``);
the short form in the title is a prefix of the full digest in the diff.

A digest names an image but says nothing about which **tag** it is, so — as with an
action SHA — resolve it upstream instead of trusting the diff. Resolve *both* the
outgoing and incoming digests and compare what they are:

```bash
curl -s "https://hub.docker.com/v2/repositories/library/<image>/tags?page_size=100" \
 | python3 -c "
import sys,json
new,old='sha256:<new>','sha256:<old>'
for r in json.load(sys.stdin).get('results',[]):
    if r.get('digest') in (new,old):
        print('NEW' if r['digest']==new else 'OLD', r['name'], r.get('last_updated'))
"
```

Official images live under `library/<image>`; a vendor image is `<owner>/<image>`.
`trixie-20260713` → `trixie-20260803` is a monthly rebuild of the same suite and is
routine; a bump whose two sides land in *different* suites is a distro upgrade
wearing a digest bump's clothes, and belongs in its own PR with the user's call.

## 7. Assert the lockfile gained nothing — and moved everything

For npm and Composer, compare the resolved lock against `main` and prove that
**every change is a version bump of a package already present**:

```bash
git show origin/main:pwa/package-lock.json   # compare package keys, not just versions
```

Report *added: 0, removed: 0* explicitly. A new package name is new ownership to
vet and belongs in the PR description, not in silence.

**That assertion is blind in the other direction, and the blindness is measured, not
hypothetical:** it returned a clean *added: 0, removed: 0* over a lock missing two of
the six bumps the batch was assembling. It proves nothing was **gained**; it never proves
everything was **moved** — a bump that silently failed to land adds no key, removes
no key, and passes. So read the resolved version of every package the batch claims
back out of the lock and check it against that PR's target:

```bash
python3 - <<'PY'
import json
old=json.load(open('/tmp/lock-main.json'))['packages']   # git show origin/main:… > this
new=json.load(open('pwa/package-lock.json'))['packages']
for p in ['<pkg>', '…']:                                  # one per superseded PR
    k=f'node_modules/{p}'
    print(f"{p}: main={old[k]['version']}  ->  branch={new[k]['version']}")
PY
```

A package still sitting at its `main` version is a bump that did not land. Find out
why before committing — it is usually the lockfile-only case in step 6 — rather than
letting the PR body assert a version the lock does not hold.

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
| docker | build the stage that carries the pin (below), or lean on the `API Build (Docker)` CI job |
| github_actions only | nothing local — CI is the gate |

The full E2E suite is CI's job unless the user asks for it locally.

**`make docker.up` is not a docker gate.** `compose.dev.yaml` builds
`target: frankenphp_dev`; only `compose.prod.yaml` builds `frankenphp_prod` — and
`api/Dockerfile` pins `debian` *inside the prod stage*. A default `ENV=dev` stack
therefore comes up green having never read the digest you changed. Build the stage
that actually carries the pin, and read the result back:

```bash
cd api && docker build --target frankenphp_prod -t deps-probe:prod . \
  > /tmp/gate-docker.log 2>&1; echo "docker build EXIT=$?"
docker run --rm --entrypoint cat deps-probe:prod /etc/os-release | head -2
docker rmi deps-probe:prod
```

The `os-release` read is the load-bearing half: a digest that silently crossed a
Debian suite builds exactly as green as one that did not.

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

A dependency batch produces no story artifact and runs no adversarial pass, so
the gate on opening a pull request (`scripts/adversarial-pass-check.sh`) will
refuse it. That is the correct verdict, not a malfunction — proceed on the
record, naming what makes this batch exempt:
`ADVERSARIAL_PASS_ACK="dependency-only batch, no source touched" <your command>`.
If step 3C turned this into a source-touching PR, the exemption no longer holds
and the pass is owed.

**Never merge** — that needs the user's explicit per-PR permission.

**Leave the dependabot PRs open.** Dependabot closes them itself on its next pass
once it sees the dependencies at target. Closing them by hand is only worth it if
they are still open days later.
