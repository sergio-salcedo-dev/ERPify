# Dark Mode v3 (Navy Slate + Blue Accent) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Re-theme `.dark` to a navy-slate ramp and replace the indigo accent family with a mode-aware blue family in both modes, per `spec-pwa-dark-mode-3.md` (approved, baseline `385d032`).

**Architecture:** Token-value-only change in `pwa/src/app/globals.css` (`:root` accent family + the whole `.dark` ramp). No token names change, no TSX changes — every consumer is token-driven since v2. The frontoffice e2e color assertion is the anti-regression lock; a Python contrast script is the AA gate; visual verification runs on the worktree's own Compose stack via playwright-cli.

**Tech Stack:** Tailwind 4 CSS tokens · Playwright e2e · `make pwa.quality` / `make pwa.test.unit` (run inside the worktree's container) · playwright-cli + system Chrome for manual verification.

**Working directory for ALL tasks:** `/home/sergio-dev/Projects/ERPify/.claude/worktrees/pwa-dark-mode-palette-ucx4` (branch `feat/pwa-dark-mode-palette-ucx4`). Never edit the primary checkout.

**Context you need (read once):**
- Spec: `_bmad-output/implementation-artifacts/spec-pwa-dark-mode-3.md` — boundaries are frozen; only token VALUES change, zero new tokens.
- e2e cannot run on this host (no Playwright browsers for this Ubuntu); e2e runs in CI. Local verification = unit + quality in container, playwright-cli + system Chrome for the browser.
- If the worktree stack was already up BEFORE you edit CSS, Turbopack may serve stale CSS: `docker compose exec pwa rm -rf .next` then restart the `pwa` container.

---

### Task 1: Flip the e2e canvas lock (test-first)

The exact-color e2e assertion is this change's regression test. Updating it first is the TDD "red": CI against current CSS would fail until Task 2 lands.

**Files:**
- Modify: `pwa/tests/e2e/frontoffice/theme.spec.ts:4-9`

- [ ] **Step 1: Update the canvas constant + comment**

Replace lines 4–9:

```ts
/**
 * Canonical dark canvas: `--erpify-bg` (#16181d) as the browser computes it.
 * Asserting the exact token value guards the v2 "elevated SaaS grey" ramp —
 * a silent revert to the v1 near-black (#08090a) fails this spec.
 */
const DARK_CANVAS_RGB = "rgb(22, 24, 29)";
```

with:

```ts
/**
 * Canonical dark canvas: `--erpify-bg` (#11151f) as the browser computes it.
 * Asserting the exact token value guards the v3 navy-slate ramp — a silent
 * revert to the v2 grey (#16181d) or the v1 near-black (#08090a) fails this spec.
 */
const DARK_CANVAS_RGB = "rgb(17, 21, 31)";
```

- [ ] **Step 2: Verify no other e2e/unit file asserts palette colors**

Run: `grep -rn "rgb(22, 24, 29)\|16181d\|7170ff\|5e6ad2" pwa/tests pwa/src --include="*.ts" --include="*.tsx" | grep -v node_modules`
Expected: only `pwa/tests/e2e/frontoffice/theme.spec.ts` hits remain until Task 2/3 edit `globals.css` (which grep doesn't cover here). If any OTHER file appears, stop and surface it.

---

### Task 2: Re-theme the `.dark` ramp in `globals.css`

**Files:**
- Modify: `pwa/src/app/globals.css:223-248` (banner comment + surface/text/border ramps)

- [ ] **Step 1: Replace the dark banner + surface ramp (lines 223–236)**

Replace:

```css
 * Dark mode — elevated SaaS grey band (GitHub dark-dimmed / Stripe)
 * ============================================================ */
.dark {
  color-scheme: dark;

  /* Surface ramp — comfortable SaaS band #16–#2b (benchmark GitHub
   * dark-dimmed #22272e + Stripe #14171d). The v1 near-black #08090a was
   * Linear's MARKETING black, not its app surface — too harsh as a canvas. */
  --erpify-bg: #16181d; /* Canvas */
  --erpify-bg-muted: #1c1f25; /* Panel */
  --erpify-bg-subtle: #22262e; /* Level 3 surface */
  --erpify-bg-elevated: #2b303a; /* Elevated / card surface */
```

with:

```css
 * Dark mode — navy slate band (v3: v2's comfortable luminance, navy undertone)
 * ============================================================ */
.dark {
  color-scheme: dark;

  /* Surface ramp — keeps v2's comfortable SaaS luminance band but with a
   * navy undertone (B > G > R) so surfaces separate with character instead
   * of reading as flat neutral grey. Benchmark: GitHub-dimmed undertone,
   * Stripe/Vercel navy band. v2 grey ramp was #16181d–#2b303a. */
  --erpify-bg: #11151f; /* Canvas */
  --erpify-bg-muted: #161b29; /* Panel */
  --erpify-bg-subtle: #1d2433; /* Level 3 surface */
  --erpify-bg-elevated: #242e42; /* Elevated / card surface */
```

- [ ] **Step 2: Replace the text ramp (lines 238–243)**

Replace:

```css
  /* Text ramp — AA (≥4.5:1) for primary + secondary across the ramp */
  --erpify-text: #edeef0;
  --erpify-text-muted: #b4bac4;
  --erpify-text-subtle: #8b919e;
  --erpify-text-faint: #646b78;
  --erpify-text-on-accent: #ffffff;
```

with:

```css
  /* Text ramp — AA (≥4.5:1) for primary + secondary across the ramp */
  --erpify-text: #e7eaf3;
  --erpify-text-muted: #aeb6cb;
  --erpify-text-subtle: #8590a8;
  --erpify-text-faint: #66708a;
  --erpify-text-on-accent: #ffffff;
```

- [ ] **Step 3: Replace borders + line-tint (lines 245–249)**

Replace:

```css
  /* Borders — semi-transparent white, bumped to read against lighter surfaces */
  --erpify-border-subtle: rgba(255, 255, 255, 0.05);
  --erpify-border: rgba(255, 255, 255, 0.09);
  --erpify-border-strong: rgba(255, 255, 255, 0.15);
  --erpify-line-tint: #191c22; /* sits between bg and bg-muted */
```

with:

```css
  /* Borders — semi-transparent blue-white so dividers share the navy undertone */
  --erpify-border-subtle: rgba(165, 180, 220, 0.07);
  --erpify-border: rgba(165, 180, 220, 0.12);
  --erpify-border-strong: rgba(165, 180, 220, 0.2);
  --erpify-line-tint: #141828; /* sits between bg and bg-muted */
```

Overlay, shadows, semantic signals (`success/warning/danger/danger-strong`): DO NOT touch — v2 values stay.

---

### Task 3: Replace the accent family (both modes) + header comments

**Files:**
- Modify: `pwa/src/app/globals.css:7-20` (header), `:151-157` (light accent), `:207` (light chart-3), `:250-256` (dark accent), `:302` (dark chart-3)

- [ ] **Step 1: Light accent family (`:root`)**

Replace:

```css
  --erpify-brand: #5e6ad2;
  --erpify-accent: #7170ff;
  --erpify-accent-hover: #828fff;
  --erpify-accent-active: #5052c4;
  --erpify-security: #7a7fad;
  --erpify-focus-ring: #7170ff;
```

with:

```css
  --erpify-brand: #2f5cd9;
  --erpify-accent: #2f5cd9;
  --erpify-accent-hover: #4a73e8;
  --erpify-accent-active: #2450b8;
  --erpify-security: #7589ad;
  --erpify-focus-ring: #2f5cd9;
```

Also replace the comment ABOVE that block (line ~149-151, currently `/* Brand and accent (same in both modes; adjusted-state shifts slightly) */`) with:

```css
  /* Brand and accent — blue family (~hue 225°), mode-aware values: one value
   * cannot be link-AA on both white and navy (the v2 indigo was 3.44:1 on
   * dark bg-elevated). Dark gets brighter accents, light gets deeper ones. */
```

- [ ] **Step 2: Light chart-3 (line 207)**

`--chart-3: #5e6ad2;` → `--chart-3: #2f5cd9;`

- [ ] **Step 3: Dark accent family (`.dark`, lines 250–256)**

Replace:

```css
  /* Brand / accent — same as light, with hover/active inverted for dark */
  --erpify-brand: #5e6ad2;
  --erpify-accent: #7170ff;
  --erpify-accent-hover: #828fff;
  --erpify-accent-active: #5052c4;
  --erpify-security: #7a7fad;
  --erpify-focus-ring: #7170ff;
```

with:

```css
  /* Brand / accent — blue family, dark-mode shades (links/active need a
   * lighter blue to stay AA on navy; buttons keep white-text AA at #3760e6) */
  --erpify-brand: #3760e6;
  --erpify-accent: #6c9bff;
  --erpify-accent-hover: #87adff;
  --erpify-accent-active: #5586f2;
  --erpify-security: #7589ad;
  --erpify-focus-ring: #6c9bff;
```

- [ ] **Step 4: Dark chart-3 (line 302)**

`--chart-3: #7170ff;` → `--chart-3: #6c9bff;`

- [ ] **Step 5: Header comment (lines 7–20)**

Line 8: `* ERPify Design Tokens — v1 (light-mode canonical, Linear-similar dark mode).` → `* ERPify Design Tokens — v3 (light-mode canonical, navy-slate dark mode).`
Line 18: `*   - Linear-derived hex values authored directly. Tailwind 4 accepts them.` → `*   - Hex values authored directly. Tailwind 4 accepts them.`

- [ ] **Step 6: Confirm the indigo family is fully gone**

Run: `grep -rn "5e6ad2\|7170ff\|828fff\|5052c4\|7a7fad" pwa/src pwa/tests | grep -v node_modules`
Expected: no output. Any hit = a missed reference; fix it before continuing.

---

### Task 4: AA contrast gate

**Files:** none (verification only)

- [ ] **Step 1: Run the contrast script — it must exit 0**

```bash
python3 - <<'EOF'
import sys
def lum(hexs):
    h = hexs.lstrip('#')
    r, g, b = (int(h[i:i+2], 16)/255 for i in (0, 2, 4))
    f = lambda c: c/12.92 if c <= 0.04045 else ((c+0.055)/1.055)**2.4
    return 0.2126*f(r) + 0.7152*f(g) + 0.0722*f(b)
def ratio(fg, bg):
    l1, l2 = sorted((lum(fg), lum(bg)), reverse=True)
    return (l1+0.05)/(l2+0.05)

S = ['#11151f', '#161b29', '#1d2433', '#242e42']   # dark surfaces
checks = [
    *[('text on ' + s, '#e7eaf3', s, 4.5) for s in S],
    *[('muted on ' + s, '#aeb6cb', s, 4.5) for s in S],
    *[('accent on ' + s, '#6c9bff', s, 4.5) for s in S],
    *[('danger-strong on ' + s, '#f87171', s, 4.5) for s in S],
    ('white on dark brand', '#ffffff', '#3760e6', 4.5),
    ('white on light brand', '#ffffff', '#2f5cd9', 4.5),
    ('light accent on bg', '#2f5cd9', '#f7f8f8', 4.5),
    ('light accent on card', '#2f5cd9', '#ffffff', 4.5),
]
fails = [(n, ratio(f, b)) for n, f, b, m in checks if ratio(f, b) < m]
for n, f, b, m in checks: print(f"{n:32s} {ratio(f,b):5.2f} (min {m})")
sys.exit(1 if fails else 0)
EOF
echo "EXIT: $?"
```

Expected: every line ≥ its min; `EXIT: 0`. Reference values (precomputed at design time): text 11.31–15.17, muted 6.70–9.00, accent 5.03–6.75, danger-strong 4.92–6.60, white-on-brand 5.28 (dark) / 5.75 (light), light accent 5.40/5.75.

- [ ] **Step 2: Commit the functional change (Tasks 1–3 together — keeps the lock and the values atomic)**

```bash
git add pwa/tests/e2e/frontoffice/theme.spec.ts pwa/src/app/globals.css
git commit -m "feat(pwa): retheme dark mode to navy slate with mode-aware blue accent

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Quality + unit tests in the worktree container

**Files:** none (verification only)

- [ ] **Step 1: Bring the worktree stack up (if not already)**

Run from the worktree root: `HTTPS_PORT=8443 make docker.up`
Expected: stack `erpify-pwa-dark-mode-palette-ucx4` up; `make docker.info` shows the resolved ports. (The fixed `HTTPS_PORT` makes Task 7's browser checks possible.)
Gotcha: if the stack was up before the CSS edits, run `docker compose exec pwa rm -rf .next && docker compose restart pwa` to flush Turbopack's stale CSS cache.

- [ ] **Step 2: Run quality**

Run: `make pwa.quality`
Expected: ESLint + Prettier clean. If Prettier reformats `globals.css` comment wrapping, run `make pwa.format`, re-stage, amend the commit.

- [ ] **Step 3: Run unit tests**

Run: `make pwa.test.unit`
Expected: 100% pass (baseline was 398/398 at v2; count may have grown). CSS token values are not unit-tested — failures here mean something unrelated broke; investigate before proceeding.

---

### Task 6: Update `pwa/DESIGN.md`

**Files:**
- Modify: `pwa/DESIGN.md:6` (inspiration), `:69-70` (principles), `:114-119` (surface table), `:121-129` (text table), `:131-138` (borders table), `:140-150` (accent section), `:152` (monogram note), `:167-169` (focus row), `:492` (color principle), `:496-503` (dark mode section), `:632` (provisional decisions)

- [ ] **Step 1: Intro line 6**

Replace:

```markdown
> **Inspiration:** Linear's restraint principles and palette discipline, applied to an ERP back-office that runs **light-mode by default**. Dark mode is a fully supported variant on the elevated SaaS grey band (GitHub dark-dimmed / Stripe).
```

with:

```markdown
> **Inspiration:** Linear's restraint principles and palette discipline, applied to an ERP back-office that runs **light-mode by default**. Dark mode is a fully supported variant on a navy-slate band (GitHub-dimmed undertone / Stripe-Vercel navy).
```

- [ ] **Step 2: Principles 2 and 3 (lines 69–70)**

Replace line 69's tail `Dark mode ships fully wired with an **elevated SaaS grey treatment**: ` + "`#16181d` canvas (GitHub-dimmed/Stripe band — see _Dark mode specifically_), semi-transparent white borders, white-opacity elevation stepping, brand indigo accent." with: `Dark mode ships fully wired with a **navy-slate treatment**: ` + "`#11151f` canvas (see _Dark mode specifically_), semi-transparent blue-white borders, luminance elevation stepping, blue accent."

Replace line 70 entirely:

```markdown
3. **Brand color is Linear-derived indigo `#5e6ad2` / `#7170ff`.** It is the only chromatic hue in the system and is the same in both modes.
```

with:

```markdown
3. **Brand color is the blue family (~hue 225°), mode-aware.** It is the only chromatic hue in the system; values diverge per mode for AA (`#2f5cd9` light, `#6c9bff`/`#3760e6` dark) — see _Color — brand and accent_.
```

- [ ] **Step 3: Surface ramp table (lines 114–119) — dark column + header**

New table (light column unchanged):

```markdown
| Token                 | Light (canonical) | Dark (navy slate)          | Use                             |
| --------------------- | ----------------- | -------------------------- | ------------------------------- |
| `--color-bg`          | `#f7f8f8`         | `#11151f` (Canvas)         | Page / canvas background        |
| `--color-bg-muted`    | `#f3f4f5`         | `#161b29` (Panel)          | Sidebar, panel background       |
| `--color-bg-subtle`   | `#e9eaec`         | `#1d2433` (Subtle Surface) | Hover surface, subtle fill      |
| `--color-bg-elevated` | `#ffffff`         | `#242e42` (Elevated)       | Card, dropdown, popover, dialog |
```

- [ ] **Step 4: Text ramp table (lines 121–129) — dark column + last row wording**

Dark column becomes `#e7eaf3` / `#aeb6cb` / `#8590a8` / `#66708a` / `#ffffff`; the `--color-text-on-accent` Use cell becomes `Text on brand-blue surfaces`.

- [ ] **Step 5: Borders table (lines 131–138) — dark column**

Dark column becomes `rgba(165,180,220,0.07)` / `rgba(165,180,220,0.12)` / `rgba(165,180,220,0.20)` / `#141828`.

- [ ] **Step 6: Brand and accent section (lines 140–150) — restructure to two-column mode-aware table**

Replace the section intro `Same in both modes. Adjusted-state values shift slightly for legibility.` and the one-column table with:

```markdown
Mode-aware: one value cannot be link-AA on both white and navy (the former indigo accent computed 3.44:1 on dark `bg-elevated`). Names stay identical; values flip in `.dark` like every other ramp.

| Token                   | Light     | Dark      | Use                                                 |
| ----------------------- | --------- | --------- | --------------------------------------------------- |
| `--color-brand`         | `#2f5cd9` | `#3760e6` | Primary CTA background, brand mark                  |
| `--color-accent`        | `#2f5cd9` | `#6c9bff` | Links, active state, selected item, focus accent    |
| `--color-accent-hover`  | `#4a73e8` | `#87adff` | Hover for accent surfaces                           |
| `--color-accent-active` | `#2450b8` | `#5586f2` | Pressed / active surfaces                           |
| `--color-security`      | `#7589ad` | `#7589ad` | Reserved for security UI (auth, audit, permissions) |
```

- [ ] **Step 7: Monogram note (line 152)** — replace both occurrences of `brand-indigo` with `brand-blue` and `"indigo is interactive/CTA only"` with `"brand blue is interactive/CTA only"`. The classes (`bg-primary/10 text-primary`) are token-driven and stay.

- [ ] **Step 8: Focus row (line 169)** — `| --color-focus-ring | #7170ff | #7170ff | …` → `| --color-focus-ring | #2f5cd9 | #6c9bff | …` (keep the Use cell).

- [ ] **Step 9: Color principles (line 492)** — `Reserve brand indigo (`#5e6ad2` / `#7170ff`) for primary CTAs and interactive accents only.` → `Reserve the brand blue (`#2f5cd9` light / `#6c9bff` dark) for primary CTAs and interactive accents only.`
Also line 493's `#edeef0` dark primary-text mention → `#e7eaf3`.

- [ ] **Step 10: "Dark mode specifically" (lines 496–503)**

Replace the first bullet with:

```markdown
- Build on a **navy-slate band**, keeping v2's comfortable luminance: `#11151f` canvas, `#161b29` panels, `#1d2433` subtle, `#242e42` elevated/cards. The navy undertone (B > G > R) gives surfaces the separation the v2 neutral grey lacked; the band itself (GitHub-dimmed / Stripe / Notion `#14`–`#22`) is unchanged — the v1 `#08090a` remains off-limits as marketing black.
```

Second bullet text ramp values → `#e7eaf3` / `#aeb6cb` / `#8590a8` / `#66708a` (the subtle-on-elevated caveat keeps the `~4.2:1` figure — new value computes 4.24:1).
Borders bullet → `rgba(165,180,220, 0.07 subtle → 0.12 default → 0.20 strong)` and "semi-transparent white" → "semi-transparent blue-white".
The `-strong` semantic-text bullet: unchanged.

- [ ] **Step 11: Provisional decisions (line 632)** — `**Brand hue.** Locked to Linear-derived indigo `#5e6ad2` / `#7170ff`. Token-only change if a stakeholder rebrands.` → `**Brand hue.** Blue family (~hue 225°), mode-aware (`#2f5cd9` light / `#6c9bff` dark). Token-only change if a stakeholder rebrands.`

- [ ] **Step 12: Sweep for leftovers**

Run: `grep -n "indigo\|violet\|5e6ad2\|7170ff\|828fff\|5052c4\|7a7fad\|16181d\|1c1f25\|22262e\|2b303a\|edeef0\|b4bac4" pwa/DESIGN.md`
Expected: no palette-bearing hits left (historical narration like "the v2 grey ramp was…" inside the new bullets is fine — judge each hit). Fix stragglers.

- [ ] **Step 13: Commit docs**

```bash
git add pwa/DESIGN.md
git commit -m "docs(pwa): update design system for dark mode v3 navy palette

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Live visual verification (worktree stack + playwright-cli)

**Files:** none (verification; screenshots for the PR)

- [ ] **Step 1: Open the worktree UI in dark mode**

```bash
playwright-cli open --browser=chrome https://localhost:8443/
playwright-cli localstorage-set erpify:theme dark
playwright-cli reload
```

Expected: landing renders with `.dark` on `<html>` and the navy canvas.

- [ ] **Step 2: Assert the computed canvas + accent (no indigo left)**

```bash
playwright-cli eval "getComputedStyle(document.querySelector('.landing-page')).backgroundColor"
```

Expected: `rgb(17, 21, 31)`.

```bash
playwright-cli eval "getComputedStyle(document.documentElement).getPropertyValue('--erpify-accent').trim()"
```

Expected: `#6c9bff`.

- [ ] **Step 3: Screenshot the three dark surfaces** (attach to the PR)

```bash
playwright-cli screenshot --filename=/tmp/v3-dark-landing.png
playwright-cli goto https://localhost:8443/status && playwright-cli screenshot --filename=/tmp/v3-dark-status.png
playwright-cli goto https://localhost:8443/backoffice/banks && playwright-cli screenshot --filename=/tmp/v3-dark-banks.png
```

Expected: navy surfaces, blue accent on active nav/links/buttons, clear sidebar/canvas/card separation, no console errors (`playwright-cli console`).

- [ ] **Step 4: Light-mode regression check**

```bash
playwright-cli localstorage-set erpify:theme light
playwright-cli goto https://localhost:8443/backoffice/banks && playwright-cli screenshot --filename=/tmp/v3-light-banks.png
```

Expected: neutrals identical to main; only CTA/links/active states changed from indigo to `#2f5cd9` blue. Close with `playwright-cli close`.

---

### Task 8: Close out spec + PR

**Files:**
- Modify: `_bmad-output/implementation-artifacts/spec-pwa-dark-mode-3.md` (tick Execution boxes, `status: 'approved'` → `'done'`)

- [ ] **Step 1: Tick the spec's Execution checkboxes and set frontmatter `status: 'done'`** (only after Tasks 1–7 all green), then:

```bash
git add _bmad-output/implementation-artifacts/spec-pwa-dark-mode-3.md
git commit -m "docs(bmad): mark dark mode v3 spec executed

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 2: Security self-review (root CLAUDE.md checklist)** — CSS token values + one test constant + docs only: no dynamic hrefs, no DOM sinks, no headers/CSP change, no deps, no storage. State this in the PR description; no checklist item applies beyond "Headers — unchanged".

- [ ] **Step 3: Push and open the PR** (ask the user first if not pre-authorized)

```bash
git push -u origin feat/pwa-dark-mode-palette-ucx4
gh pr create --title "feat(pwa): dark mode v3 — navy slate ramp, mode-aware blue accent" --body "<summary + AA table + the 4 screenshots + security note>

🤖 Generated with [Claude Code](https://claude.com/claude-code)"
```

Expected: CI runs the full e2e suite including the updated canvas lock.

---

## Self-review (done at plan-writing time)

- **Spec coverage:** ramp ✓ (Task 2), accent both modes ✓ (Task 3), e2e lock ✓ (Task 1), DESIGN.md ✓ (Task 6), CLAUDE.md ✓ (checked — zero hex refs, no edit needed), backoffice theme.spec.ts ✓ (checked — no color asserts), AA gate ✓ (Task 4), visual evidence ✓ (Task 7), quality/unit ✓ (Task 5).
- **Placeholders:** none — every edit shows exact before/after content.
- **Consistency:** the 7 accent values and 4 surface values are identical across Tasks 2/3/4/6 and match the approved spec tables.
