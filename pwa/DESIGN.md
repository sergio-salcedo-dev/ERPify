# ERPify PWA Design System

Lean implementation-facing reference for polishing the ERPify back-office PWA. Day-to-day artifact for engineers; full design spec lives at `_bmad-output/planning-artifacts/ux-design-specification.md`.

> **Status:** v1, brownfield-safe, applied iteratively. Tokens land first; components consume tokens; composites wrap Shadcn primitives. No big-bang rewrite.
> **Inspiration:** Linear's restraint principles, palette discipline, and dark-mode treatment, applied to an ERP back-office that runs **light-mode by default**. Dark mode is a fully supported Linear-similar variant.

---

## Reconciliation notes (departures from prior project conventions)

Three deliberate departures from the project's earlier defaults — load-bearing for the polish identity. Documented here so future readers don't undo them by accident.

1. **Geist + Geist Mono via `next/font/google` is the only typeface dependency.** Self-hosted, zero CLS, no third-party network call. The earlier "no web fonts in v1" rule is superseded — Geist is Vercel's default for new Next.js projects and ships as the typography baseline. Geist Mono replaces the substitute-for-Berkeley-Mono concern in one decision.
2. **Light mode is the canonical default.** Most ERP back-office operators work in light all day. Light mode uses conventional sRGB neutrals + drop shadows. Dark mode ships fully wired with **Linear-similar treatment**: near-black canvas (`#08090a`), semi-transparent white borders, white-opacity elevation stepping, brand indigo accent.
3. **Brand color is Linear-derived indigo `#5e6ad2` / `#7170ff`.** It is the only chromatic hue in the system and is the same in both modes.

---

## TL;DR — what this system is

- **Light-mode default, Linear-similar dark mode.** Light is the canonical authoring environment. Dark mode is the Linear-treatment variant for operators who prefer it.
- **Geist + Geist Mono.** Loaded via `next/font/google`. Three weights: `400` reading, `500` emphasis, `600` strong emphasis. No OpenType feature toggling.
- **Tokens-first.** Every color, type size, radius, and elevation lives as a CSS variable in `src/app/globals.css` `@theme`. Light and dark share the same alias contract. (Spacing rides Tailwind's default scale; motion is reduced-at-root but durations aren't yet tokenized — see those sections.)
- **Shadcn primitives are unforked.** ERPify-specific composites live in `src/components/erpify/` and wrap Shadcn primitives via slots and `cn()`.
- **RFC 9457 is a first-class UI primitive.** `<ProblemDisplay>` consumes the API error envelope verbatim — `title`, `detail`, `violations[]`, copyable `correlation-id`.
- **Four-state async surfaces are mandatory.** Every fetched surface wraps in `<AsyncBoundary>` with explicit idle / loading / empty / error.
- **Pessimistic-by-default UI.** No optimistic flashes. Errors stay where the action was attempted. Data is preserved across failed submits.
- **Keyboard-first.** Every primary task is completable without a mouse. Focus rings are always visible, in both modes.
- **Brand indigo, used with intent.** Primary action and active state only. Everything else is grayscale.

---

## Principles (the system enforces these)

1. **Honest over delightful.** No optimistic theater, no swallowed errors, no celebratory copy.
2. **Quiet by default, loud only on signal.** Color, motion, and emphasis are reserved tokens — they carry meaning when they appear. Brand indigo is the _only_ chromatic color in the system; everything else is grayscale.
3. **Density is a feature.** Compact is default; comfortable is opt-in.
4. **Keyboard is the canonical input.** First-class, never a fallback.
5. **One way to do each thing.** Single skeleton pattern, single error pattern, single empty pattern, single primary-action color.
6. **Brownfield-safe iteration.** Every token and primitive can be applied to one component at a time without breaking the rest.
7. **Mode-aware elevation.** Light mode uses conventional surface stepping + faint drop shadows. Dark mode uses Linear's white-opacity stepping; drop shadows are reserved for floating affordances.

---

## Tokens — the contract

All tokens live in `src/app/globals.css`. The wiring is three layers, top to bottom:

1. **Raw ramp values** are authored as `--erpify-*` custom properties in `:root` (light, canonical) and `.dark` (Linear-similar). This is the only place a hex value appears, and the only place light/dark diverge.
2. **`@theme inline {}`** re-exports them as the semantic `--color-*` aliases this document names (`--color-bg → var(--erpify-bg)`, etc.) **and** maps the Shadcn-named tokens (`--background`, `--primary`, `--card`, …) onto the same ramp so unforked Shadcn primitives work without edits.
3. **Components consume the aliases** — never the raw `--erpify-*` ramp, never a literal hex.

Linear's hex values are authored directly — Tailwind 4 accepts hex, sRGB, and oklch interchangeably.

> **Alias-name caveat.** Two semantic names collide with Shadcn's own theme keys, so the ERPify alias carries a `-default` suffix in `@theme`: the brand violet is **`--color-accent-default`** (`--color-accent` is Shadcn's, mapped to `--color-bg-subtle`) and the default border is **`--color-border-default`** (`--color-border` is Shadcn's, same value). The tables below use the short conceptual names; reach for the `-default` alias when consuming the CSS variable directly.

### Color — surface ramp

| Token                 | Light (canonical) | Dark (Linear-similar)         | Use                             |
| --------------------- | ----------------- | ----------------------------- | ------------------------------- |
| `--color-bg`          | `#f7f8f8`         | `#08090a` (Marketing Black)   | Page / canvas background        |
| `--color-bg-muted`    | `#f3f4f5`         | `#0f1011` (Panel Dark)        | Sidebar, panel background       |
| `--color-bg-subtle`   | `#e9eaec`         | `#191a1b` (Level 3 Surface)   | Hover surface, subtle fill      |
| `--color-bg-elevated` | `#ffffff`         | `#28282c` (Secondary Surface) | Card, dropdown, popover, dialog |

### Color — text ramp

| Token                    | Light (canonical) | Dark      | Use                           |
| ------------------------ | ----------------- | --------- | ----------------------------- |
| `--color-text`           | `#08090a`         | `#f7f8f8` | Body — never pure white/black |
| `--color-text-muted`     | `#62666d`         | `#d0d6e0` | Secondary body, descriptions  |
| `--color-text-subtle`    | `#8a8f98`         | `#8a8f98` | Placeholders, metadata        |
| `--color-text-faint`     | `#9ea2a8`         | `#62666d` | Timestamps, disabled-ish      |
| `--color-text-on-accent` | `#ffffff`         | `#ffffff` | Text on brand-indigo surfaces |

### Color — borders

| Token                   | Light (canonical) | Dark                     | Use                                           |
| ----------------------- | ----------------- | ------------------------ | --------------------------------------------- |
| `--color-border-subtle` | `#eef0f2`         | `rgba(255,255,255,0.05)` | Faintest divider                              |
| `--color-border`        | `#dcdfe3`         | `rgba(255,255,255,0.08)` | Default border for cards, inputs, code blocks |
| `--color-border-strong` | `#bfc3ca`         | `#23252a`                | Emphasized divider                            |
| `--color-line-tint`     | `#f3f4f5`         | `#141516`                | Whisper-line dividers between rows            |

### Color — brand and accent (the only chromatic hue in the system)

Same in both modes. Adjusted-state values shift slightly for legibility.

| Token                   | Value                           | Use                                                 |
| ----------------------- | ------------------------------- | --------------------------------------------------- |
| `--color-brand`         | `#5e6ad2` (Linear Brand Indigo) | Primary CTA background, brand mark                  |
| `--color-accent`        | `#7170ff` (Accent Violet)       | Links, active state, selected item, focus accent    |
| `--color-accent-hover`  | `#828fff`                       | Hover for accent surfaces                           |
| `--color-accent-active` | `#5052c4`                       | Pressed / active surfaces                           |
| `--color-security`      | `#7a7fad` (Security Lavender)   | Reserved for security UI (auth, audit, permissions) |

### Color — semantic signals (sparse use; ERP-defined statuses go through `<StatusBadge>`)

| Token                    | Light               | Dark      | Use                            |
| ------------------------ | ------------------- | --------- | ------------------------------ |
| `--color-success`        | `#10b981` (Emerald) | `#10b981` | Pill, complete state           |
| `--color-success-strong` | `#27a644`           | `#27a644` | Active / in-progress dot       |
| `--color-warning`        | `#d97706`           | `#f59e0b` | Warning surfaces               |
| `--color-danger`         | `#dc2626`           | `#e5484d` | Destructive action, error icon |

### Color — overlay and focus

| Token                | Light               | Dark               | Use                                          |
| -------------------- | ------------------- | ------------------ | -------------------------------------------- |
| `--color-overlay`    | `rgba(8,9,10,0.45)` | `rgba(0,0,0,0.85)` | Modal backdrop                               |
| `--color-focus-ring` | `#7170ff`           | `#7170ff`          | 2 px focus ring on every interactive element |

### Typography

```text
--font-sans: <Geist>, -apple-system, system-ui, "Segoe UI", Roboto, "Helvetica Neue", sans-serif
--font-mono: <Geist Mono>, ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace
```

Geist + Geist Mono are loaded via `next/font/google` in `src/app/layout.tsx`:

```ts
import { Geist, Geist_Mono } from "next/font/google";

const geistSans = Geist({ subsets: ["latin"], variable: "--font-sans", preload: false });
const geistMono = Geist_Mono({ subsets: ["latin"], variable: "--font-mono", preload: false });
```

`<html>` gets `cn("font-sans", geistSans.variable, geistMono.variable)` on `className` (composed via `cn()`, never a string template). Tailwind's `font-sans` / `font-mono` utilities resolve through these CSS variables; components consume Tailwind utility classes (`font-sans`, `font-mono`) — they do not reference the variable directly. `@theme` also aliases `--font-heading` to `--font-sans` so headings share the Geist face.

#### Type scale (Linear-derived sizing; weights collapsed to Geist 400 / 500 / 600)

| Role          | Size (rem / px) | Weight    | Line height | Tracking  |
| ------------- | --------------- | --------- | ----------- | --------- |
| Display XL    | 4.5 / 72        | 500       | 1.00        | -1.584 px |
| Display Large | 4.0 / 64        | 500       | 1.00        | -1.408 px |
| Display       | 3.0 / 48        | 500       | 1.00        | -1.056 px |
| Heading 1     | 2.0 / 32        | 400       | 1.13        | -0.704 px |
| Heading 2     | 1.5 / 24        | 400       | 1.33        | -0.288 px |
| Heading 3     | 1.25 / 20       | 600       | 1.33        | -0.24 px  |
| Body Large    | 1.125 / 18      | 400       | 1.60        | -0.165 px |
| Body Emphasis | 1.0625 / 17     | 600       | 1.60        | normal    |
| Body          | 1.0 / 16        | 400       | 1.50        | normal    |
| Body Medium   | 1.0 / 16        | 500       | 1.50        | normal    |
| Body Semibold | 1.0 / 16        | 600       | 1.50        | normal    |
| Small         | 0.9375 / 15     | 400       | 1.60        | -0.165 px |
| Small Medium  | 0.9375 / 15     | 500       | 1.60        | -0.165 px |
| Caption Large | 0.875 / 14      | 500 / 600 | 1.50        | -0.182 px |
| Caption       | 0.8125 / 13     | 400 / 500 | 1.50        | -0.130 px |
| Label         | 0.75 / 12       | 400 / 600 | 1.40        | normal    |
| Micro         | 0.6875 / 11     | 500       | 1.40        | normal    |
| Tiny          | 0.625 / 10      | 400 / 500 | 1.50        | -0.150 px |
| Mono Body     | 0.875 / 14      | 400       | 1.50        | normal    |
| Mono Caption  | 0.8125 / 13     | 400       | 1.50        | normal    |
| Mono Label    | 0.75 / 12       | 400       | 1.40        | normal    |

The role table above is the design vocabulary (sizes / weights / tracking per role). The **implemented** size tokens are a compact Tailwind scale in `@theme`: `--text-2xs` 11 px · `--text-xs` 12 px · `--text-sm` 13 px · `--text-base` 14 px · `--text-md` 16 px · `--text-lg` 18 px · `--text-xl` 20 px · `--text-2xl` 24 px · `--text-3xl` 30 px · `--text-4xl` 48 px · `--text-5xl` 64 px · `--text-6xl` 72 px. Note the density default: `<body>` is set to `--text-base` **(14 px)**, so the role-table "Body / 16 px" is the _comfortable_ reading size (`--text-md`), not the compact default. Weight and tracking are applied per-component; only the sizes are tokenized.

#### Typography principles (non-negotiable)

- **Three weights only:** 400 (read), 500 (emphasize / UI), 600 (announce). Weight 700+ is forbidden.
- **No OpenType feature toggling required.** Geist's default character set is already clean/geometric — engineers do not need to remember `font-feature-settings`.
- **Negative tracking scales with size.** Display sizes get aggressive negative tracking (Linear-derived); ≤ 16 px stays at normal or near-normal.
- **Tabular numerics** (`font-variant-numeric: tabular-nums`) on every numeric table cell.
- **Geist Mono only for monospace.** Code, identifiers (UUIDs, correlation IDs), tabular metadata. No system mono fallback in our own components — Geist Mono is the canonical mono.

### Spacing

Base unit 8 px. Standard rhythm: 8, 16, 24, 32 px.

Spacing rides on **Tailwind 4's default scale** (`p-2` = 8 px, `gap-4` = 16 px, …) — no custom `--space-*` tokens are emitted in `globals.css`. The scale below is the rhythm the system standardizes on; use the Tailwind utility, not a bespoke variable. The original spec's optical micro-steps (7 / 11 / 19 / 35 px) are **not** on the Tailwind scale and were intentionally dropped in favor of Tailwind's nearest steps (`1.5` = 6 px, `2.5` = 10 px, `5` = 20 px, `9` = 36 px); reintroduce them as tokens only if a surface demonstrably needs the half-pixel optical tuning.

```text
px → 1px     1   → 4px      1.5 → 6px
2  → 8px     2.5 → 10px     3   → 12px
4  → 16px    5   → 20px     6   → 24px
7  → 28px    8   → 32px     9   → 36px
12 → 48px    16  → 64px     20  → 80px
```

### Radii

```text
--radius-micro:  2px    inline badges, toolbar buttons
--radius-sm:     4px    small containers, list items
--radius-md:     6px    buttons, inputs, functional elements (default)
--radius-lg:     8px    cards, dropdowns, popovers
--radius-xl:    12px    panels, featured cards, command palette
--radius-2xl:   22px    large panel elements
--radius-3xl:   24px    extra-large panel elements
rounded-full   9999px   chips, filter pills, status tags
```

`--radius-micro … --radius-3xl` are emitted in `@theme`. The full pill uses Tailwind's built-in `rounded-full` rather than a custom token. Table cells stay sharp — no radius.

### Density

| Element       | Compact (default) | Comfortable (opt-in) |
| ------------- | ----------------- | -------------------- |
| Button height | 32 px             | 36 px                |
| Input height  | 32 px             | 36 px                |
| Table row     | 36 px             | 44 px                |
| List row      | 40 px             | 48 px                |
| Pill          | 22 px             | 26 px                |

### Elevation

Two systems, one for each mode. Both are token-driven; components do not branch on mode. The shadow values live behind mode-agnostic aliases — `--shadow-elevation-0` … `--shadow-elevation-5` plus `--shadow-elevation-inset` (Tailwind `shadow-elevation-*` utilities) — whose underlying `--erpify-shadow-*` values flip in `:root` vs. `.dark`. A component asks for `shadow-elevation-4`; the mode decides whether that renders as a drop shadow (light) or a luminance ring (dark).

#### Light mode (canonical) — sRGB neutrals + faint drop shadows

| Level | Treatment                                                  | Use                                    |
| ----- | ---------------------------------------------------------- | -------------------------------------- |
| 0     | `--color-bg`, no shadow                                    | Page canvas                            |
| 1     | `--color-bg-muted` (subtle gray)                           | Toolbar, sidebar                       |
| 2     | `--color-bg-elevated` (white) + `1px solid --color-border` | Cards, inputs                          |
| 3     | Shadow: `0 1px 2px rgba(8,9,10,0.06)`                      | Subtle resting elevation               |
| 4     | Shadow: `0 4px 8px -2px rgba(8,9,10,0.08)`                 | Dropdowns, popovers                    |
| 5     | Shadow: `0 16px 32px -8px rgba(8,9,10,0.12)`               | Dialogs, command palette               |
| Focus | `0 0 0 2px --color-focus-ring`, offset 2 px                | Keyboard focus on interactive elements |

#### Dark mode (Linear-similar) — luminance stepping, not drop shadow

On dark surfaces, traditional shadows (dark-on-dark) read as nothing. Linear conveys depth by **stepping the surface's white opacity upward** as elevation rises. Drop shadows are reserved for floating elements and even there are very low-opacity multi-layer stacks.

| Level   | Treatment                                                                                                                                                             | Use                                    |
| ------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------- |
| 0       | `--color-bg` (`#08090a`), no shadow                                                                                                                                   | Page canvas                            |
| 1       | `--color-bg-muted` (`#0f1011`)                                                                                                                                        | Toolbar, sidebar                       |
| 2       | `--color-bg-subtle` (`#191a1b`) + `1px solid --color-border`                                                                                                          | Cards, inputs                          |
| 2-inset | `box-shadow: inset 0 0 12px 0 rgba(0,0,0,0.2)`                                                                                                                        | Recessed panels, code blocks           |
| 3       | Border-as-shadow: `box-shadow: 0 0 0 1px rgba(0,0,0,0.2)`                                                                                                             | Subtle ring around hovered elements    |
| 4       | Multi-layer: `0 2px 4px rgba(0,0,0,0.4)`                                                                                                                              | Dropdowns, floating affordances        |
| 5       | Linear command-palette stack: `0 8px 2px rgba(0,0,0,0), 0 5px 2px rgba(0,0,0,0.01), 0 3px 2px rgba(0,0,0,0.04), 0 1px 1px rgba(0,0,0,0.07), 0 0 1px rgba(0,0,0,0.08)` | Modals, command palette, popovers      |
| Focus   | `0 0 0 2px --color-focus-ring` + `0 4px 12px rgba(0,0,0,0.1)`                                                                                                         | Keyboard focus on interactive elements |

### Motion

The duration scale below is the intended vocabulary, applied today via Tailwind/inline durations and the Shadcn primitives' own transitions. Named `--duration-*` tokens are **not** yet emitted in `globals.css`; add them if a third surface needs to share a value (the threshold for tokenizing).

```text
instant   0ms      operator-initiated state change
fast      120ms    hover, micro-feedback
base      180ms    modal, drawer, popover enter/exit
slow      240ms    page-level transition (rare)
```

- Easing: `cubic-bezier(0.16, 1, 0.3, 1)` (ease-out, no bounce).
- `prefers-reduced-motion` **is** handled once at the root of `globals.css`: a global `@media (prefers-reduced-motion: reduce)` rule collapses every `animation-duration` / `transition-duration` to `0 ms` and forces `scroll-behavior: auto`. Components do not need to re-implement this.

### Breakpoints

| Name  | Min width | Key changes                                         |
| ----- | --------- | --------------------------------------------------- |
| `sm`  | 640       | two-column form layouts available                   |
| `md`  | 768       | sidebar transitions from sheet to persistent        |
| `lg`  | 1024      | full 12-column grid; tables in native form          |
| `xl`  | 1280      | comfortable margins; multi-pane workspaces possible |
| `2xl` | 1536      | secondary panels can surface inline                 |

---

## Mandatory composite primitives

Live in `pwa/src/components/erpify/`. Wrap Shadcn primitives via slots and `cn()`. Forkable; Shadcn primitives are not.

> **Status (v1):** all 9 mandatory composites are built and unit-tested, plus three supporting primitives (`<CopyButton>`, `<DateField>`, `<DatePickerField>` — see below). Import via `@/components/erpify`. Tests at `pwa/tests/components/erpify/*.test.tsx` (12 files, 69 tests passing).

### `<ProblemDisplay>`

Renders an RFC 9457 envelope. Slots: icon · title · detail · violations · `<CorrelationIdChip>` · primary action. Variants: `inline` (default), `panel`, `compact`. `aria-live` per surface urgency. **Never paraphrase API `title` or `violations[].message`.** Border: `1px solid --color-danger` at 25 % opacity. Background: `--color-bg-elevated` with a faint danger tint.

### `<AsyncBoundary>`

Wraps any async surface. Slots: `idle`, `loading`, `empty`, `error`. Loading defaults: skeleton (lists, detail), button-spinner (actions), progress (known-duration). Empty distinguishes `first-run` / `filtered-to-zero` / `permission-denied` via `<EmptyState>`. Error renders `<ProblemDisplay>`.

### `<DataTable>`

Dense, sortable, keyboard-navigable. Sticky header on `--color-bg-muted`, zebra rows alternating `--color-bg` and a tiny opacity step (`rgba(8,9,10,0.02)` light / `rgba(255,255,255,0.01)` dark), per-row actions menu, pagination footer (cursor-based — never `OFFSET`). Density toggle. Keyboard: `↑↓` rows, `Enter` opens, `Space` selects, `/` focuses search. Identifier columns use mono; numeric columns use tabular-nums + right-align.

### `<FormField>`

react-hook-form-aware. Slots: label · required indicator · input · helper · error. Errors pull from RHF + RFC 9457 `violations[]`. On submit failure, first invalid field receives focus. `aria-invalid` + `aria-describedby` linked correctly. Input background `--color-bg-elevated` (light) / `rgba(255,255,255,0.02)` (dark); border `1px solid --color-border`.

### `<RecordSheet>`

Drawer (default) or dialog. Title + subtitle + body (read or edit) + footer actions. Pessimistic submit. Dirty-state confirmation on close. Focus trap on open, focus restore on close. Background `--color-bg-elevated` with elevation-5 shadow (light) or multi-shadow stack (dark). `aria-labelledby` references title.

### `<StatusBadge>`

Color + icon + label. Variants: `success` (`--color-success`, white text, full-pill), `warning`, `danger`, `info`, `neutral` (transparent bg, `--color-text-muted`, `1px solid --color-border-strong`, full-pill). CVA-driven; cannot pass arbitrary colors. Color is never the sole signal.

### `<CorrelationIdChip>`

Geist Mono, truncated middle (`01926e7…f5c6`), one-click copy with 2-second copied affordance. Background `--color-bg-subtle` (light) / `rgba(255,255,255,0.05)` (dark), `1px solid --color-border-subtle`, `--radius-micro`, font 12 px weight 500. Tooltip shows full ID. `aria-live="polite"` on copy success. First-class to every error and audit surface.

### `<EmptyState>`

Three variants only: `first-run` (invitation), `filtered-to-zero` (clear-filter affordance), `permission-denied` (honest, non-blaming). Real heading element + supporting copy + action. **No decorative illustrations.** Heading uses Heading 3 token (20 px / 600); supporting copy uses Body (16 px / 400 / `--color-text-muted`).

### `<AppShell>`

Persistent chrome: sidebar (`--color-bg-muted`, collapsible, persisted in localStorage) · top bar (`--color-bg`) · content slot · global toast region. Skip-to-content link. `Cmd/Ctrl+B` toggles sidebar. Mobile `< 768 px` converts sidebar to sheet. Bottom border on top bar: `1px solid --color-border-subtle`.

### Button variants

Values are mode-aware via tokens; the table shows the alias each variant consumes.

| Variant                    | Bg                                                              | Text                     | Border                            | Radius          | Use                         |
| -------------------------- | --------------------------------------------------------------- | ------------------------ | --------------------------------- | --------------- | --------------------------- |
| **Brand** (primary CTA)    | `--color-brand`                                                 | `--color-text-on-accent` | none                              | `--radius-md`   | "Save", "Submit", "Create"  |
| **Ghost** (default action) | `--color-bg-elevated` (light) / `rgba(255,255,255,0.02)` (dark) | `--color-text`           | `1px solid --color-border`        | `--radius-md`   | Standard actions, secondary |
| **Subtle** (toolbar)       | transparent                                                     | `--color-text-muted`     | none                              | `--radius-md`   | Toolbar, contextual         |
| **Icon** (circular)        | `--color-bg-subtle`                                             | `--color-text`           | `1px solid --color-border`        | `--radius-full` | Close, menu toggle          |
| **Pill** (filter chip)     | transparent                                                     | `--color-text-muted`     | `1px solid --color-border-strong` | `--radius-full` | Tags, filters, status       |
| **Destructive**            | `--color-danger`                                                | `#ffffff`                | none                              | `--radius-md`   | Delete, remove              |

### Supporting primitives

Exported from the same `@/components/erpify` barrel. Not part of the "mandatory four-state / error / form" contract, but cross-entity enough to live beside the composites rather than being re-implemented per feature.

- **`<CopyButton value testId>`** — canonical copy-to-clipboard control. Owns the success/error feedback flip, the icon swap, the `sr-only` fallback, and the async-clipboard → `execCommand` degradation path. Never trusts the value as HTML. `<CorrelationIdChip>` builds on it; entity components must use it instead of calling `navigator.clipboard.writeText` directly.
- **`<DateField testId>`** — the canonical `dd/mm/yyyy` text input: correct `pattern` / `inputMode` / `placeholder` / tooltip and the `(dd/mm/yyyy)` label hint, exported alongside the `DD_MM_YYYY_*` constants. Pairs with the `dateTimeProvider.parseDdMmYyyyToStartTimestamp` / `parseDdMmYyyyToEndTimestamp` methods (from `@/context/shared/infrastructure/DateTimeProvider`) for inclusive filter bounds.
- **`<DatePickerField>`** — wraps the **native** `<input type="date">` (`yyyy-mm-dd`) inside `<FormField>`, with `min` / `max` bounds and `violations[]` wiring. Zero added dependency — distinct from the deferred third-party date-picker _library_ (see "Out of scope"); use it where a native picker is acceptable and `<DateField>`'s free-text `dd/mm/yyyy` is not.

All three take a `testId` prop rather than hardcoding a `data-testid` (per the PWA test-id uniqueness contract).

---

## Patterns

### Form submission

- Pessimistic. Submit button shows a spinner and stays in place.
- `violations[]` → field-level inline errors via `<FormField>`. First invalid field receives focus.
- Non-violation 4xx/5xx → `<ProblemDisplay panel>` above the form, with retry. Data preserved.
- Success → optional ambient toast + return to context. **Never a celebratory animation.**

### List view

- Filter bar above table, debounced 250 ms.
- Sort persists in URL params.
- Keyset (cursor) pagination. Never `OFFSET`.
- Row click opens `<RecordSheet>` drawer (default).
- Selection persists across pagination.
- Mobile: filters in a sheet, table renders as cards.

### Async loading

- ≤ 100 ms response → render directly, no loading state.
- 100 ms – 1 s → skeleton (layout-stable), button-spinner (actions), progress (known-duration).
- > 3 s → "still loading…" hint announced via `aria-live="polite"`.
- Errors transition to `<ProblemDisplay>`.

### Confirmation

- Dialog (not drawer) for irreversible actions.
- `Esc` cancels.
- Destructive primary action uses `--color-danger`, has a verbal label ("Delete invoice", not "OK"), and does **not** auto-focus.
- Pessimistic submit. Errors keep the dialog open with `<ProblemDisplay>` inline.

### Notification (toast)

- **Ambient events only.** Never used for confirmation of operator-initiated actions.
- Auto-dismiss 5 s. Pause on hover. Max 3 stacked.
- `role="status"` for non-urgent; `role="alert"` for urgent.

---

## RFC 9457 consumption — the API error envelope

Every API non-2xx response returns:

```jsonc
{
  "type": "bank-not-found", // opaque kebab-case identifier
  "title": "Bank not found", // user-safe headline
  "status": 404,
  "detail": "...", // optional, user-safe
  "instance": "01926e7…f5c6", // UUIDv7, per error
  "correlation-id": "01926e7…0001", // UUIDv7, per request
  "violations": [
    {
      // optional, on 422
      "field": "name",
      "message": "must not be blank",
      "code": "NotBlank",
    },
  ],
}
```

**UI rules:**

- `title` rendered verbatim. **Never** paraphrase or replace.
- `detail` rendered verbatim if present.
- `violations[]` distributed to fields by `field` name.
- `instance` shown only in the error UI as the user-citable error reference (the support-ticket handle).
- `correlation-id` shown via `<CorrelationIdChip>` — copyable, mono.
- `type` is the discriminator for client-side routing.

**Forbidden:** inventing a parallel UI error shape, transforming `title` for "friendliness," hiding `correlation-id` to "reduce noise," displaying stack traces or framework internals.

---

## Accessibility — non-negotiables

- **WCAG 2.2 AA** baseline. Body contrast ≥ 4.5:1; UI controls ≥ 3:1.
- Focus rings always visible in both modes. 2 px solid `--color-focus-ring`, offset 2 px.
- Hit targets ≥ 24 × 24 px (44 × 44 px on touch).
- Color is never the sole signal. Always pair with icon, label, or position.
- `prefers-reduced-motion` respected at the token layer.
- Keyboard-only path through every primary task; `Esc` closes overlays; focus restores after dialogs.
- Real heading hierarchy. No `<h2>` for visual size.
- Form labels are mandatory and visible. No placeholder-as-label.
- `aria-live` regions: `polite` for ambient + validation; `assertive` for action errors and system alerts.
- Skip-to-content link in `<AppShell>`.
- **Primary text is `#08090a` (light) / `#f7f8f8` (dark)** — never pure black or pure white. Pure values cause eye strain over a workday.

---

## Linear-derived "do" / "don't" — operative rules

### Always (both modes)

- Use Geist for sans, Geist Mono for mono. Loaded via `next/font/google`.
- Use weight 500 as the default emphasis weight.
- Apply aggressive negative letter-spacing at display sizes (-1.584 px at 72 px, scaling down).
- Reserve brand indigo (`#5e6ad2` / `#7170ff`) for primary CTAs and interactive accents only.
- Use `#08090a` (light) / `#f7f8f8` (dark) for primary text — never pure black/white.
- Color is never the sole signal — always pair with icon, label, or position.

### Dark mode specifically

- Build on near-black backgrounds: `#08090a` canvas, `#0f1011` panels, `#191a1b` elevated.
- Use semi-transparent white borders (`rgba(255,255,255,0.05)` to `0.08`) — not solid dark borders.
- Keep ghost button backgrounds nearly transparent: `rgba(255,255,255,0.02–0.05)`.
- Convey elevation via background luminance stepping; reserve drop shadows for floating elements.

### Light mode specifically

- Build on `#f7f8f8` canvas with `#ffffff` cards and `#f3f4f5` panels.
- Use sRGB neutral borders (`#dcdfe3` default; `#bfc3ca` strong).
- Use faint drop shadows for resting and elevated surfaces; surface stepping is also valid.

### Never

- Pure white (`#ffffff`) as primary body text.
- Pure black (`#000000`) as primary body text.
- Brand indigo applied decoratively. CTA / interactive only.
- Positive letter-spacing at display sizes.
- Weight 700+ — the system maxes at 600.
- Warm colors in UI chrome.
- Drop shadows for elevation on dark surfaces (use opacity stepping instead).
- Decorative empty-state illustrations.
- Toasts for action confirmation.
- Reaching past `cn()` to string-concat class names.

---

## Governance — when each pattern wins

| Decision                               | Rule                                                                                                                                                                                                                                                                                                                                      |
| -------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Extending Shadcn vs. wrapping          | **Always wrap.** Composites in `src/components/erpify/`. Never modify upstream Shadcn files.                                                                                                                                                                                                                                              |
| Tailwind utility vs. BEM custom class  | Default to utility. BEM only when utilities cannot express the rule cleanly.                                                                                                                                                                                                                                                              |
| `cn()` vs. string concat               | **Always `cn()`.** Never string-concat class names.                                                                                                                                                                                                                                                                                       |
| Inline `style=` vs. utility            | Utility unless the value is genuinely dynamic.                                                                                                                                                                                                                                                                                            |
| Adding a dependency                    | `class-variance-authority` is implicitly approved. Geist + Geist Mono via `next/font/google` are approved. Other additions require explicit pre-approval. Currently deferred-with-approval-required: virtualized table, third-party date-picker library, charting. (The native `<DatePickerField>` adds no dependency and is already in.) |
| Optimistic UI                          | Pessimistic by default. Optimistic is opt-in per action and requires a documented rollback path.                                                                                                                                                                                                                                          |
| Toast for confirmation                 | **Never.** Confirmation lives in the action's surface.                                                                                                                                                                                                                                                                                    |
| Decorative illustration in empty state | **Never.** Small icon + clear copy + recovery action.                                                                                                                                                                                                                                                                                     |
| Forking a Shadcn primitive             | **Never** (in v1). Wrap, don't modify.                                                                                                                                                                                                                                                                                                    |
| Web fonts                              | Geist + Geist Mono via `next/font/google`. No third-party font loaders. No additional fonts.                                                                                                                                                                                                                                              |
| Mode authoring                         | Light is canonical. Build and review in light first, then verify dark. Both ship.                                                                                                                                                                                                                                                         |

---

## Day-to-day workflow for polishing a feature

1. **Check tokens.** If your component needs a value not in the alias contract, propose a token addition (PR to `globals.css`). Do not hard-code colors, sizes, or durations.
2. **Wrap async surfaces in `<AsyncBoundary>`.** All four states (idle, loading, empty, error) are mandatory.
3. **Use `<ProblemDisplay>` for any API error.** Pass the RFC 9457 envelope as-is.
4. **Keyboard-walk your feature** before you ship. No mouse for one full canonical loop (scan → filter → open → act → confirm → return).
5. **Light mode first, then dark.** Build and review in light, then toggle dark and verify focus rings, contrast, elevation behavior, and that the Linear treatment lands.
6. **Mobile-walk if reachable on mobile.** Same component, responsive utilities. No parallel mobile component.
7. **Test what matters.** Vitest for state branches and a11y attributes; Playwright for keyboard-only walk-through and RFC 9457 error consumption against a stubbed API.

---

## Component prompt examples (for consistency when generating UI)

- "Create a hero section on `--color-bg`. Headline at Display (48 px) Geist weight 500, line-height 1.00, letter-spacing -1.056 px, color `--color-text`. Subtitle at Body Large (18 px) weight 400, color `--color-text-subtle`. Brand button (`--color-brand` bg, `--radius-md`, 8 px 16 px padding) and ghost button (`--color-bg-elevated` bg / `rgba(255,255,255,0.02)` in dark, `1px solid --color-border` border, `--radius-md`)."
- "Design a card on `--color-bg`: `--color-bg-elevated` background, `1px solid --color-border`, `--radius-lg`. Title at Heading 3 (20 px / 600), letter-spacing -0.24 px, color `--color-text`. Body at Small (15 px / 400), color `--color-text-subtle`, letter-spacing -0.165 px."
- "Build a pill badge: transparent bg, `--color-text-muted`, `--radius-full`, 0 px 10 px padding, `1px solid --color-border-strong`, Label (12 px / 500)."
- "Create navigation: sticky header on `--color-bg-muted`. Caption (13 px / 500) for links, `--color-text-muted`. Brand CTA right-aligned with `--radius-md`. Bottom border: `1px solid --color-border-subtle`."
- "Design a command palette: `--color-bg-elevated` background, `1px solid --color-border`, `--radius-xl`, elevation-5 shadow. Input at Body (16 px / 400), `--color-text`. Results list with Caption (13 px / 500) labels in `--color-text-muted` and Label (12 px) metadata in `--color-text-faint`."

---

## Phase 4 — adoption playbook

The token layer (Phase 1), Shadcn audit (Phase 2), and composite library (Phase 3) are shipped. Phase 4 is **adoption**: feature teams migrate or author against the system.

### When you build a new feature

1. **Compose, don't copy.** Reach for the ERPify composite first; reach for a raw Shadcn primitive only if no composite fits.
2. **Wrap async surfaces in `<AsyncBoundary>`.** All four states (`idle` / `loading` / `empty` / `error`) are mandatory; `ready` renders your data.
3. **Render API errors with `<ProblemDisplay>`.** Pass the RFC 9457 envelope verbatim. Never paraphrase `title` or `violations[].message`.
4. **Render forms with `<FormField>`.** Pass `error` from RHF or `violations` from the API envelope; the component picks the right one.
5. **Use `<DataTable>` for any list view.** Provide `columns`, `data`, `rowKey`, `caption`. Sort/select state lives in the parent so URL params or DI-resolved services own the truth.
6. **Wrap detail views in `<RecordSheet>`.** Drawer for inline detail/edit, dialog for short confirmations.
7. **Wrap routes in `<AppShell>`.** Sidebar + top bar + content; `Cmd/Ctrl+B` toggles, persists in `localStorage`.

### Migrating an existing surface

Step-by-step for a typical brownfield page (the landing page is the canonical example):

1. Replace raw Tailwind palette utilities (`bg-slate-50`, `text-blue-600`, `bg-emerald-50`) with token utilities (`bg-background`, `text-primary`, `bg-card`). The slate/blue palette is **not** part of the system; it predates it and bypasses dark-mode parity.
2. Replace bespoke loading / empty / error blocks with `<AsyncBoundary>`.
3. Replace bespoke error rendering with `<ProblemDisplay>`.
4. Replace ad-hoc badges and chips with `<StatusBadge>` and `<CorrelationIdChip>`.
5. Replace bespoke table markup with `<DataTable>`.
6. Run the surface in **light mode first, then dark**. Verify focus rings, contrast, and elevation.

### Adoption status

| Surface                                                            | Status                       | Notes                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| ------------------------------------------------------------------ | ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/app/backoffice/health/page.tsx`                               | **migrated (Phase 5)**       | Uses `<AsyncBoundary>` for state machine. Synthesizes `ProblemDetails` on error as a temporary bridge until the BackOffice CheckHealth adapter returns RFC 9457 envelopes (TODO marked in source).                                                                                                                                                                                                                                       |
| `src/app/backoffice/page.tsx` (dashboard)                          | **migrated (Phase 5)**       | Token swap: slate/blue/emerald/amber/rose → semantic tokens (`text-foreground`, `text-primary`, `text-success`, `text-warning`, `text-destructive`). StatCard / PlaceholderCard molecules left as-is (props-driven; out of scope).                                                                                                                                                                                                       |
| `src/app/backoffice/BackOfficeLayoutClient.tsx`                    | **token-migrated (Phase 5)** | Slate/blue palette swapped to tokens. **Not** restructured to `<AppShell>` — the existing sidebar has multi-level submenu expand/collapse that v1 `<AppShell>` doesn't support, and e2e tests (`tests/e2e/backoffice/sidebar.spec.ts`) depend on the existing button structure. Promote to `<AppShell>` once it gains submenu support.                                                                                                   |
| `src/app/backoffice/banks/**`                                      | **authored on system**       | Canonical "author against the system" surface (the `pwa/CLAUDE.md` running example). `<DataTable>` + `<BanksCards>` responsive list, `<BanksFilters>` with `<DateField>`, keyset `<BanksPagination>`, `<RecordSheet>`-style `<BankForm>` create/edit, `<DeleteBankButton>` confirmation dialog surfacing failures via `<ProblemDisplay variant="inline">`, `<CopyButton>` for ids. The reference for how a new entity should look.       |
| `src/context/shared/error/infrastructure/ui/**`                    | **authored on system**       | Token-native error module: `<ErrorScreen>` shell + `<ErrorActions>` + per-surface Screens (`<NotFoundScreen>`, `<AccessDeniedScreen>` 403, `<SignInRequiredScreen>` 401, `<SegmentErrorBoundary>` 500, `<RootErrorBoundary>`). Backs the Next convention files (`error.tsx`, `not-found.tsx`, `global-error.tsx`, …) and the navigable `app/(errors)/*` routes. Boundary kept explicit — **not** re-exported from `@/components/erpify`. |
| `src/context/shared/dev-tools/infrastructure/ui/**`                | **authored on system**       | Token-native internal QA hub at `/dev-tools`, gated by `isDevToolsAvailable()` and short-circuited in production via `src/proxy.ts`. Dev-only surface; ships disabled in prod builds.                                                                                                                                                                                                                                                    |
| `src/app/page.tsx` (landing)                                       | **un-migrated**              | Public marketing surface. Uses raw slate/blue and custom `Navbar` / `Footer` / `FeatureCard` molecules. Out of scope for v1 (back-office-first). Migrate when the landing page next ships a change.                                                                                                                                                                                                                                      |
| `src/context/shared/infrastructure/ui/components/atoms/Button.tsx` | **un-migrated**              | Custom Button atom with motion + raw blue/slate/emerald colors. Used by health page (now replaced with Shadcn `Button`) and Navbar. Replace fully once Navbar migrates.                                                                                                                                                                                                                                                                  |

### Lint enforcement (deferred)

A long-term goal is an ESLint rule that flags raw Shadcn primitive use where an ERPify composite exists, plus a rule that flags raw palette utilities (`text-slate-*`, `bg-blue-*`) outside `node_modules`. **No longer blocked**: the project now ships `pwa/eslint.config.mjs` (the flat config `make pwa.quality` runs), so these custom rules have a host. **Not yet written** — author them as a local plugin / `no-restricted-syntax` block in that config and wire them into `make pwa.quality`.

---

## Out of scope (v1)

- Offline mode, service worker, push notifications, native packaging.
- Visual regression testing infrastructure.
- Berkeley Mono (replaced by Geist Mono).
- Inter Variable + OpenType feature toggling (replaced by Geist).
- Categorical chart palette (defer until a charting feature lands).
- Command palette UI (`Cmd/Ctrl+K` reserved; not built).
- Saved views.
- Virtualized data grid; `<DataTable>` v1 renders a flat `<tbody>` and is fine up to ~500 rows.
- Third-party date-picker library and charting library — reach when a real feature requires. (A dependency-free native `<DatePickerField>` already ships; this defers a richer calendar library only.)
- The two custom ESLint rules (composite-over-primitive, no-raw-palette). The ESLint config now exists (`eslint.config.mjs`), so this is unblocked but unwritten; see Phase 4 above.

---

## Provisional decisions to confirm

- **Persona.** "Finance/operations back-office operator" pending confirmation. Token-only impact if changed.
- **Brand hue.** Locked to Linear-derived indigo `#5e6ad2` / `#7170ff`. Token-only change if a stakeholder rebrands.
- **Persistent sidebar collapse default.** Currently expanded; flip to collapsed if telemetry shows otherwise.
- **Light-mode ramp tuning.** The light-mode neutrals (`#f7f8f8`, `#f3f4f5`, `#e9eaec`, `#dcdfe3`, `#bfc3ca`) are first-pass. Refine after the first feature surface ships and we see them in context.
- **`--color-warning` light value `#d97706`** is provisional; pick a final low-chroma amber when the first warning surface ships.

Updates to this file: PRs that change tokens, primitives, or patterns must update the relevant section here. The full spec at `_bmad-output/planning-artifacts/ux-design-specification.md` is the canonical reference for rationale.
