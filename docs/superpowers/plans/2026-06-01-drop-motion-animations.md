# Drop `motion` from the PWA Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the `motion` (`motion/react`) dependency from the PWA, re-expressing every animation with `tw-animate-css` utilities already in the bundle or plain Tailwind/CSS, so `motion` leaves `package.json`.

**Architecture:** Four `src/` files import `motion/react` (`app/page.tsx`, `app/_components/Navbar.tsx`, `app/_components/FeatureCard.tsx`, `components/erpify/StatCard.tsx`). Each `<motion.*>` is replaced by a plain element plus `animate-in fade-in-0 slide-in-from-*` classes (entrance) or `transition-transform hover:scale-*` (hover). A source-walking guard test — mirroring the repo's existing `tests/data-testid-uniqueness.test.ts` / `tests/proxy.test.ts` invariant guards — locks the removal in. The global `prefers-reduced-motion` block in `globals.css` already neutralises the CSS animations, so no per-component motion handling is needed.

**Tech Stack:** Next.js 16 (App Router), TypeScript (strict), Tailwind 4, `tw-animate-css` (already a dependency, imported at `pwa/src/app/globals.css:2`), Vitest, Playwright.

**Spec:** `docs/superpowers/specs/2026-06-01-drop-motion-animations-design.md`

---

## File Structure

| File | Change | Responsibility after change |
|---|---|---|
| `pwa/tests/no-motion-dependency.test.ts` | **Create** | Guard: fails if any `src/` file imports `motion` or if `motion` is in `package.json`. Permanent regression guard. |
| `pwa/src/app/page.tsx` | Modify | Landing page; hero + health-status entrances via `tw-animate-css`. No `motion` import. |
| `pwa/src/app/_components/FeatureCard.tsx` | Modify | Feature card; hover scale via CSS. No `motion` import. |
| `pwa/src/app/_components/Navbar.tsx` | Modify | Navbar; mobile menu enter via `tw-animate-css`, instant close. No `motion`/`AnimatePresence` import. |
| `pwa/src/components/erpify/StatCard.tsx` | Modify | Backoffice stat card; staggered entrance via `tw-animate-css` + inline `animationDelay`. No `motion` import. |
| `pwa/package.json` + `pwa/package-lock.json` | Modify | `motion` removed from dependencies; lockfile pruned. |
| `pwa/CLAUDE.md` | Modify | Landing design-language description no longer names `motion/react`. |
| `docs/architecture-pwa.md` | Modify | Dependency table Animation row reflects `tw-animate-css`, not `motion`. |
| `docs/project-context.md` | Modify | Stack lists drop `motion`. |

**Out of scope (pre-existing stale docs about the retired `context/shared/infrastructure/ui/components/` folder — already wrong before this change):** `docs/deep-dive-pwa-shared-infrastructure.md`, `docs/architecture-pwa.md:63,68`. Leave them; recommend a separate docs-cleanup follow-up. Do **not** touch `pwa/DESIGN.md` — its "motion" references are the design *concept* (prefers-reduced-motion), not the npm package.

---

## Task 1: Regression guard test (TDD red)

**Files:**
- Create: `pwa/tests/no-motion-dependency.test.ts`

This test is the invariant anchor. It must FAIL now (motion is still imported in 4 files and still in `package.json`) and turn GREEN once the conversion + dep removal are done. **Do not stage or commit it in this task** — it is committed in Task 6, born green. (This keeps every commit's test suite passing while still validating the guard red-first here.)

- [ ] **Step 1: Write the guard test**

```ts
import { readFileSync, readdirSync, statSync } from "node:fs";
import path from "node:path";
import { describe, expect, it } from "vitest";

/**
 * `motion` (framer's motion/react) was removed from the PWA on 2026-06-01:
 * every animation it powered was re-expressed with `tw-animate-css` utilities
 * (already in the bundle) or plain Tailwind / CSS. This guard keeps it gone —
 * re-adding the dependency or importing `motion` / `motion/react` from `src/`
 * fails CI, the same way the `data-testid-uniqueness` and `proxy` guards lock
 * in their invariants. See docs/superpowers/specs/2026-06-01-drop-motion-animations-design.md.
 */

const PWA_ROOT = path.resolve(__dirname, "..");
const SRC_ROOT = path.join(PWA_ROOT, "src");
const SOURCE_EXTENSIONS = new Set([".ts", ".tsx", ".js", ".jsx"]);
// Matches `from "motion"` and `from "motion/react"`, but not `framer-motion`,
// `motion-dom`, or `motion-utils` (the char after `motion` must be `/` or the quote).
const MOTION_IMPORT_RE = /from\s+["']motion(?:\/[^"']*)?["']/;

function* walk(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const full = path.join(dir, entry);
    const s = statSync(full);
    if (s.isDirectory()) {
      yield* walk(full);
    } else if (s.isFile() && SOURCE_EXTENSIONS.has(path.extname(full))) {
      yield full;
    }
  }
}

describe("motion dependency removal", () => {
  it("no source file imports from `motion`", () => {
    const offenders: string[] = [];
    for (const file of walk(SRC_ROOT)) {
      if (MOTION_IMPORT_RE.test(readFileSync(file, "utf8"))) {
        offenders.push(path.relative(PWA_ROOT, file));
      }
    }
    expect(
      offenders,
      offenders.length > 0
        ? "These files still import `motion`; re-express the animation with " +
            `tw-animate-css / CSS instead:\n${offenders.map((f) => `  ${f}`).join("\n")}`
        : "",
    ).toEqual([]);
  });

  it("`motion` is not a declared dependency", () => {
    const pkg = JSON.parse(readFileSync(path.join(PWA_ROOT, "package.json"), "utf8")) as {
      dependencies?: Record<string, string>;
      devDependencies?: Record<string, string>;
    };
    expect(pkg.dependencies ?? {}).not.toHaveProperty("motion");
    expect(pkg.devDependencies ?? {}).not.toHaveProperty("motion");
  });
});
```

- [ ] **Step 2: Run the guard and confirm it fails**

Run (from repo root): `make pwa.test.unit c='tests/no-motion-dependency.test.ts'`
(First run also triggers `pwa.install.if-missing` → `npm ci`, populating `node_modules` in this fresh worktree.)
Expected: FAIL — `no source file imports from \`motion\`` lists the 4 offending files (`src/app/page.tsx`, `src/app/_components/Navbar.tsx`, `src/app/_components/FeatureCard.tsx`, `src/components/erpify/StatCard.tsx`), and `\`motion\` is not a declared dependency` fails because `package.json` still has it.

- [ ] **Step 3: Do not commit.** Leave the file in the working tree (uncommitted). It will be committed in Task 6 once green.

---

## Task 2: Convert `app/page.tsx` (hero + health-status entrances)

**Files:**
- Modify: `pwa/src/app/page.tsx`

- [ ] **Step 1: Remove the motion import**

Delete this line (currently line 8):

```tsx
import { motion } from "motion/react";
```

- [ ] **Step 2: Replace the hero `<motion.h1>`**

Replace:

```tsx
            <motion.h1
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              className="landing-page__title text-4xl md:text-6xl font-extrabold text-slate-900 mb-6 tracking-tight"
            >
              Modern ERP for <span className="text-blue-600">Construction</span>
            </motion.h1>
```

with:

```tsx
            <h1 className="landing-page__title text-4xl md:text-6xl font-extrabold text-slate-900 mb-6 tracking-tight animate-in fade-in-0 slide-in-from-bottom-4 duration-700">
              Modern ERP for <span className="text-blue-600">Construction</span>
            </h1>
```

- [ ] **Step 3: Replace the hero `<motion.p>` subtitle (keeps the staggered 100ms delay)**

Replace:

```tsx
            <motion.p
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.1 }}
              className="landing-page__subtitle text-lg md:text-xl text-slate-600 max-w-2xl mx-auto"
            >
              Streamline your projects, manage your workforce, and track every brick with Erpify.
              The all-in-one solution for construction management.
            </motion.p>
```

with:

```tsx
            <p
              className="landing-page__subtitle text-lg md:text-xl text-slate-600 max-w-2xl mx-auto animate-in fade-in-0 slide-in-from-bottom-4 duration-700"
              style={{ animationDelay: "100ms", animationFillMode: "both" }}
            >
              Streamline your projects, manage your workforce, and track every brick with Erpify.
              The all-in-one solution for construction management.
            </p>
```

(`animationFillMode: "both"` holds the opacity-0 start during the 100ms delay so the subtitle doesn't flash visible-then-fade.)

- [ ] **Step 4: Replace the health-status `<motion.div>` (preserve the testid)**

Replace:

```tsx
                <motion.div
                  data-testid="frontoffice-health-status"
                  initial={{ opacity: 0, height: 0 }}
                  animate={{ opacity: 1, height: "auto" }}
                  className="landing-page__health-status mt-6 p-4 bg-slate-50 rounded-xl text-sm font-mono text-slate-600 border border-slate-200 w-full"
                >
                  {healthStatus}
                </motion.div>
```

with:

```tsx
                <div
                  data-testid="frontoffice-health-status"
                  className="landing-page__health-status mt-6 p-4 bg-slate-50 rounded-xl text-sm font-mono text-slate-600 border border-slate-200 w-full animate-in fade-in-0 slide-in-from-top-1 duration-300"
                >
                  {healthStatus}
                </div>
```

- [ ] **Step 5: Verify motion is gone from this file and it type-checks**

Run: `grep -n "motion" pwa/src/app/page.tsx` → Expected: no output.
Run (from repo root): `make pwa.lint.dry-run c='src/app/page.tsx'`
Expected: PASS (no ESLint errors). If ESLint reports `style` ordering or similar, run `make pwa.lint` to auto-fix.

- [ ] **Step 6: Commit (stage only this file)**

```bash
git add pwa/src/app/page.tsx
git commit -m "refactor(pwa): drop motion from landing hero + health status"
```

---

## Task 3: Convert `app/_components/FeatureCard.tsx` (hover scale)

**Files:**
- Modify: `pwa/src/app/_components/FeatureCard.tsx`

- [ ] **Step 1: Remove the motion import**

Delete this line (currently line 2):

```tsx
import { motion } from "motion/react";
```

- [ ] **Step 2: Replace the `<motion.div>` wrapper with a CSS hover transition**

Replace (currently line 35):

```tsx
    <motion.div whileHover={{ scale: 1.02 }} className="feature-card w-full min-w-0">
```

with:

```tsx
    <div className="feature-card w-full min-w-0 transition-transform duration-200 hover:scale-[1.02]">
```

- [ ] **Step 3: Replace the closing tag**

Replace the matching closing tag (currently line 68):

```tsx
    </motion.div>
```

with:

```tsx
    </div>
```

- [ ] **Step 4: Verify motion is gone and it type-checks**

Run: `grep -n "motion" pwa/src/app/_components/FeatureCard.tsx` → Expected: no output.
Run (from repo root): `make pwa.lint.dry-run c='src/app/_components/FeatureCard.tsx'`
Expected: PASS.

- [ ] **Step 5: Commit (stage only this file)**

```bash
git add pwa/src/app/_components/FeatureCard.tsx
git commit -m "refactor(pwa): drop motion from FeatureCard hover"
```

---

## Task 4: Convert `app/_components/Navbar.tsx` (mobile menu)

**Files:**
- Modify: `pwa/src/app/_components/Navbar.tsx`

The mobile menu loses its **exit** animation (closes instantly on toggle) — this is the one intentional behavioural change (standard for a hamburger menu). Enter animation is preserved.

- [ ] **Step 1: Remove the motion import**

Delete this line (currently line 2):

```tsx
import { motion, AnimatePresence } from "motion/react";
```

(Keep `import React, { useState } from "react";` on line 1 — `useState` is still used.)

- [ ] **Step 2: Replace the `AnimatePresence` + `<motion.div>` mobile menu block**

Replace (currently lines 145-185):

```tsx
      {/* Mobile Menu */}
      <AnimatePresence>
        {isMenuOpen && (
          <motion.div
            initial={{ opacity: 0, y: -10 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -10 }}
            className="navbar__mobile-menu md:hidden bg-white border-b border-slate-200 px-4 pt-2 pb-6 space-y-4"
          >
            {navLinks.map((link) => (
              <a
                key={link.name}
                href={link.href}
                data-testid={`${link.testId}--mobile`}
                className="navbar__link block text-slate-600 font-medium"
              >
                {link.name}
              </a>
            ))}
            {showDevTools ? (
              <Link
                href={Routes.DEV_TOOLS}
                className="navbar__link navbar__link--dev-tools text-amber-700 hover:text-amber-900 font-medium inline-flex items-center gap-1.5"
                title="Internal QA / engineering tools (dev/test only)"
                data-testid="navbar__mobile-dev-tools-link"
              >
                <Wrench className="w-4 h-4" aria-hidden="true" />
                Dev Tools
              </Link>
            ) : null}
            <Button
              onClick={onGetStarted}
              size="lg"
              className="navbar__button w-full rounded-xl"
              data-testid="navbar__get-started--mobile"
            >
              Get Started
            </Button>
          </motion.div>
        )}
      </AnimatePresence>
```

with:

```tsx
      {/* Mobile Menu */}
      {isMenuOpen && (
        <div className="navbar__mobile-menu md:hidden bg-white border-b border-slate-200 px-4 pt-2 pb-6 space-y-4 animate-in fade-in-0 slide-in-from-top-2 duration-200">
          {navLinks.map((link) => (
            <a
              key={link.name}
              href={link.href}
              data-testid={`${link.testId}--mobile`}
              className="navbar__link block text-slate-600 font-medium"
            >
              {link.name}
            </a>
          ))}
          {showDevTools ? (
            <Link
              href={Routes.DEV_TOOLS}
              className="navbar__link navbar__link--dev-tools text-amber-700 hover:text-amber-900 font-medium inline-flex items-center gap-1.5"
              title="Internal QA / engineering tools (dev/test only)"
              data-testid="navbar__mobile-dev-tools-link"
            >
              <Wrench className="w-4 h-4" aria-hidden="true" />
              Dev Tools
            </Link>
          ) : null}
          <Button
            onClick={onGetStarted}
            size="lg"
            className="navbar__button w-full rounded-xl"
            data-testid="navbar__get-started--mobile"
          >
            Get Started
          </Button>
        </div>
      )}
```

- [ ] **Step 3: Verify motion is gone and it type-checks**

Run: `grep -n "motion\|AnimatePresence" pwa/src/app/_components/Navbar.tsx` → Expected: no output.
Run (from repo root): `make pwa.lint.dry-run c='src/app/_components/Navbar.tsx'`
Expected: PASS.

- [ ] **Step 4: Commit (stage only this file)**

```bash
git add pwa/src/app/_components/Navbar.tsx
git commit -m "refactor(pwa): drop motion from Navbar mobile menu"
```

---

## Task 5: Convert `components/erpify/StatCard.tsx` (staggered entrance)

**Files:**
- Modify: `pwa/src/components/erpify/StatCard.tsx`

Keep the `"use client"` directive — `StatCard` is consumed by the client dashboard, and dropping the directive risks an RSC boundary surprise for zero user benefit (out of scope).

- [ ] **Step 1: Remove the motion import**

Delete this line (currently line 4):

```tsx
import { motion } from "motion/react";
```

- [ ] **Step 2: Replace the `<motion.div>` opening tag (preserve the per-index stagger)**

Replace (currently lines 27-32):

```tsx
    <motion.div
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ delay: index * 0.1 }}
      className="stat-card"
    >
```

with:

```tsx
    <div
      className="stat-card animate-in fade-in-0 slide-in-from-bottom-4 duration-500"
      style={{ animationDelay: `${index * 100}ms`, animationFillMode: "both" }}
    >
```

(`index` is a numeric prop — no user input, no XSS surface. `animationFillMode: "both"` holds the opacity-0 start through each card's stagger delay.)

- [ ] **Step 3: Replace the closing tag**

Replace the matching closing tag (currently line 44):

```tsx
    </motion.div>
```

with:

```tsx
    </div>
```

- [ ] **Step 4: Verify motion is gone and it type-checks**

Run: `grep -n "motion" pwa/src/components/erpify/StatCard.tsx` → Expected: no output.
Run (from repo root): `make pwa.lint.dry-run c='src/components/erpify/StatCard.tsx'`
Expected: PASS.

- [ ] **Step 5: Commit (stage only this file)**

```bash
git add pwa/src/components/erpify/StatCard.tsx
git commit -m "refactor(pwa): drop motion from StatCard stagger"
```

---

## Task 6: Remove the `motion` dependency + commit the guard (TDD green)

**Files:**
- Modify: `pwa/package.json`
- Modify: `pwa/package-lock.json` (regenerated)
- Commit: `pwa/tests/no-motion-dependency.test.ts` (created in Task 1)

- [ ] **Step 1: Confirm no source imports remain**

Run: `grep -rn "motion/react" pwa/src/` → Expected: no output.

- [ ] **Step 2: Remove the dependency line from `package.json`**

Delete this line (currently line 34 of `pwa/package.json`):

```json
    "motion": "^12.40.0",
```

- [ ] **Step 3: Sync the lockfile (prunes `motion` + its transitive packages)**

PWA tooling runs on the host (login shell), not the container. Run in the PWA dir:

```bash
cd pwa && npm install
```

Expected: `package-lock.json` updated; `npm` removes `motion` and its transitive deps (`motion-dom`, `motion-utils`). No errors.

- [ ] **Step 4: Run the guard test — now GREEN**

Run (from repo root): `make pwa.test.unit c='tests/no-motion-dependency.test.ts'`
Expected: PASS — both `it` blocks green (no source imports, `motion` absent from `package.json`).

- [ ] **Step 5: Commit (dep removal + the now-green guard together)**

```bash
git add pwa/package.json pwa/package-lock.json pwa/tests/no-motion-dependency.test.ts
git commit -m "refactor(pwa): remove motion dependency + add regression guard"
```

---

## Task 7: Update documentation

**Files:**
- Modify: `pwa/CLAUDE.md`
- Modify: `docs/architecture-pwa.md`
- Modify: `docs/project-context.md`

- [ ] **Step 1: `pwa/CLAUDE.md` — landing design-language description**

In the "Where shared code goes" table row for `app/_components/` (currently line 34), replace `(its own raw-palette + \`motion/react\` language)` with `(its own raw-palette + \`tw-animate-css\` / CSS language)`.

In the Note paragraph below the table (currently line 39), replace `the landing/marketing surface (raw-palette + \`motion/react\`, under \`app/_components/\`)` with `the landing/marketing surface (raw-palette + \`tw-animate-css\` / CSS, under \`app/_components/\`)`.

- [ ] **Step 2: `docs/architecture-pwa.md` — dependency table Animation row**

Replace the table row (currently line 19):

```markdown
| Animation       | motion                                            | 12      |
```

with:

```markdown
| Animation       | tw-animate-css (+ CSS)                            | 1       |
```

(Leave lines 63 and 68 — they describe the retired `context/shared/infrastructure/ui/components/` folder and are pre-existing stale; flagged as a follow-up, out of scope here.)

- [ ] **Step 3: `docs/project-context.md` — stack lists**

On the UI-kit row (currently line 52), remove `, motion` from the list `Shadcn, Base UI React, tw-animate-css, tailwind-merge, cva, lucide-react, motion` so it ends `…, lucide-react`.

On the animations line (currently line 154), replace `animations via \`motion\` / \`tw-animate-css\`` with `animations via \`tw-animate-css\` / CSS`.

- [ ] **Step 4: Verify the in-scope docs no longer name the package**

Run: `grep -rn "motion/react\|\"motion\"\|, motion\b\| motion / " pwa/CLAUDE.md docs/architecture-pwa.md docs/project-context.md`
Expected: no output (the spec/plan files under `docs/superpowers/` may still mention motion — that is fine and expected).

- [ ] **Step 5: Commit**

```bash
git add pwa/CLAUDE.md docs/architecture-pwa.md docs/project-context.md
git commit -m "docs(pwa): drop motion from PWA design-language + stack docs"
```

---

## Task 8: Full verification + security self-review

**Files:** none (verification only; commit fixups if any).

- [ ] **Step 1: No motion anywhere it shouldn't be**

Run: `grep -rn "motion/react" pwa/src/` → Expected: empty.
Run: `grep -n "\"motion\"" pwa/package.json` → Expected: empty.

- [ ] **Step 2: Lint + format**

Run (from repo root): `make pwa.quality`
Expected: ESLint + Prettier PASS. If Prettier reports formatting, run `make pwa.format` and re-run; commit any formatting fixups with `git commit -m "style(pwa): prettier fixups"`.

- [ ] **Step 3: Production build (proves tw-animate-css classes survive purge + TS is happy)**

Run (from repo root): `make pwa.production.build`
Expected: build succeeds with no type errors and no "unknown utility class" warnings for the new `animate-in` / `slide-in-from-*` / `hover:scale-[1.02]` classes.

- [ ] **Step 4: Unit tests (guard green + nothing regressed)**

Run (from repo root): `make pwa.test.unit`
Expected: all PASS, including `motion dependency removal` and `data-testid uniqueness`.

- [ ] **Step 5: Landing E2E**

Bring the stack up if needed (`make app.dev` or `make docker.up` from inside the worktree), then run:
Run (from repo root): `make pwa.test.e2e c='tests/e2e/frontoffice/landing.spec.ts'`
Expected: 3 tests PASS (hero heading visible, CTA → /backoffice, health check renders in `frontoffice-health-status`).

- [ ] **Step 6: Visual eyeball (browse from a non-colliding port)**

Bring the worktree UI up: `HTTPS_PORT=8443 make docker.up` (from inside the worktree), open `https://localhost:8443`. Confirm:
  - Landing hero title + subtitle fade up on load (subtitle a beat after the title).
  - Hovering a feature card scales it slightly.
  - Mobile menu (narrow viewport) slides+fades in on open; closes instantly.
  - `/backoffice` dashboard stat cards fade up in a staggered cascade.
  - Toggle OS "reduce motion" → animations collapse (inherited from the global guard).

- [ ] **Step 7: Security self-review (mandatory per CLAUDE.md)**

Confirm and note in the eventual PR description:
  - **XSS:** no `dangerouslySetInnerHTML` / `innerHTML` / dynamic `href`/`src` introduced. The only new dynamic value is `style={{ animationDelay: \`${index * 100}ms\` }}` — a numeric prop, not user input.
  - **Dependencies:** a dependency was *removed*, none added; `npm audit` no worse than before.
  - **Headers/CSP:** `next.config.ts` untouched; no inline `<script>` or `eval`. The CSS animations need no CSP change.
  - All other checklist classes: not applicable (no auth, input parsing, SQL, or network surface touched).

- [ ] **Step 8: Final state check**

Run: `git status` → Expected: clean (everything committed).
Run: `git log --oneline origin/main..HEAD` → Expected: the spec commit + the task commits, all on `refactor/pwa-drop-motion-animations`, none on `main`.

---

## Self-Review (completed during planning)

- **Spec coverage:** all four `motion/react` files (page, FeatureCard, Navbar, StatCard) → Tasks 2-5; dep removal → Task 6; reduced-motion (free, no task needed); testid preservation → Task 2 Step 4 + Task 8 Step 5; docs coupling discovered during planning → Task 7. Guard test (not in the spec but matches repo convention) → Task 1/6.
- **Placeholder scan:** every code step shows full before/after; every run step states the expected result. No TBD/TODO.
- **Type/name consistency:** `animationFillMode: "both"` + `animationDelay` used identically in page subtitle (Task 2) and StatCard (Task 5); class set `animate-in fade-in-0 slide-in-from-*` consistent across tasks; the guard test's regex and file list match the 4 files converted in Tasks 2-5.
