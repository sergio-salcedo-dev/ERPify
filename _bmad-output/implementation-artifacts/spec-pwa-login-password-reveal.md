---
title: 'Reveal toggle on the login password field, and a gate that keeps it there'
type: 'bugfix'
created: '2026-09-03'
status: 'done'
baseline_commit: '54f17ff19e331c28d348591d3f2e18e9e4e22785'
review_loop_iteration: 0
context:
  - '{project-root}/pwa/CLAUDE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `PasswordInput` already exists (`Eye`/`EyeOff` toggle, static accessible name, `aria-pressed`, 44px target) and six forms use it. The login form was left out: `LoginForm.tsx:118` still renders a bare `<Input type="password">`, so the most-used password field in the product has no reveal affordance, and nothing in the suite noticed.

**Approach:** Adopt `PasswordInput` in the login form (starting masked), flip the component's default to masked so an omission fails toward hiding rather than showing, and add an AST gate asserting that `PasswordInput.tsx` is the only file in `pwa/src` that statically declares a password-typed input.

## Boundaries & Constraints

**Always:**

- The login input keeps `data-testid="login-form__password"` and `autoComplete="current-password"`; the toggle gets `login-form__password-toggle` (naming contract; `data-testid-uniqueness.test.ts` forbids a duplicate literal).
- The login field starts **masked**, stated explicitly at the call site even though it now matches the component default — the default protects future consumers, the explicit prop states this flow's own decision. Do not collapse the two in the name of DRY.
- New copy is English (`ui-copy-language.test.ts`); the toggle's accessible name never changes with state.
- The gate carries no baseline and no exemption list, reports **every** violation with `file:line`, and asserts its two invariants separately.

**Ask First:**

- Any exemption added to the gate, or widening it beyond `type` attributes (to `secret`/`token` naming, `React.createElement`, or resolving `type={variable}`).

**Never:**

- Change the login request contract, the `LoginOutcome` handling, or any existing `data-testid`.
- Change the reveal behaviour of the six current call sites — all six pass `defaultRevealed` explicitly, so the default flip must be a no-op for them.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Login field first paint | Login page loads | `type="password"`; toggle `aria-pressed="false"`, `Eye` icon | N/A |
| Reveal / re-mask | Click the toggle, then again | `type` goes `text` → `password`; `aria-pressed` follows; accessible name identical throughout; the typed value survives both | N/A |
| Value reaches the port | Type `secret123`, submit | The login port is called with exactly `{ email, password: "secret123" }` | Existing invalid-credentials path unchanged |
| Component default | `<PasswordInput />`, no `defaultRevealed` | Starts masked | N/A |
| Gate detects | A file declares `type="password"`, `type={"password"}`, `` type={`password`} ``, `type={c ? "text" : "password"}` or `type={c && "password"}` | Fails, listing every offending `file:line` | N/A |
| Gate ignores | `const password = "password"`, a commented-out `type="password"`, `type={type}`, `type="text"` | No finding | N/A |
| Gate universe empties | `PasswordInput.tsx` renamed, moved, or no longer declaring a password type | Fails on the "at least one declaration" assertion | N/A |

</frozen-after-approval>

## Code Map

- `pwa/src/app/(auth)/_components/LoginForm.tsx` — the last bare `<Input type="password">` (line 118), inside `<FormField name="password">`.
- `pwa/src/components/ui/PasswordInput.tsx` — `defaultRevealed = true` today; spreads every input prop through to `<Input>`, which is what lets RHF keep ownership. It sets no `spellCheck`/`autoCorrect`/`autoCapitalize`, and only one of its seven call sites does (`RecoveryRedeemForm`).
- `pwa/src/components/erpify/FormField.tsx` — clones its single child injecting `id`/`aria-*`; child props win the spread, so `<Label htmlFor>` keeps pointing at the real input (already true for the existing adopters).
- `pwa/tests/app/(auth)/loginForm.test.tsx` — its container mock is `login: () => repoLogin()`, which **drops the arguments**: no test today proves the typed password reaches the port. `LoginSchema` (`password: min(1)`) only detects a *disconnected* field, never a *corrupted* value.
- `pwa/tests/data-testid-uniqueness.test.ts` — matches `data-testid="…"` only, so every `toggleTestId` literal in the tree is outside it.
- `pwa/tests/ui-copy-language.test.ts` — the AST-walk pattern to follow (TypeScript compiler, file walk, blind spots stated in the header).
- `pwa/tests/e2e/backoffice/login.spec.ts`, `logout.spec.ts` — address the field by testid; must keep passing untouched.

## Tasks & Acceptance

**Execution:**

- [x] `pwa/src/components/ui/PasswordInput.tsx` — `defaultRevealed = false`; prop doc states the current rule with no change-relative wording.
- [x] `pwa/src/app/(auth)/_components/LoginForm.tsx` — swap in `<PasswordInput autoComplete="current-password" defaultRevealed={false} toggleTestId="login-form__password-toggle" {...register("password")} data-testid="login-form__password" />`. Keep the `Input` import — the email field still uses it.
- [x] `pwa/src/components/ui/PasswordInput.tsx` — set `spellCheck={false}`, `autoCorrect="off"` and `autoCapitalize="none"` ahead of the spread, and hide `::-ms-reveal`. Only this component knows the field can become `type="text"`, where a cloud spell checker uploads the value and iOS silently capitalises it; a caller can still override.
- [x] `pwa/tests/password-input-adoption.test.ts` — the gate. Walk `pwa/src/**/*.{ts,tsx,js,jsx}`; for every `JSXAttribute` named `type` **and** every `type` property of an inline spread object, search the value subtree for a string literal (or no-substitution template literal) whose text is `password` **case-insensitively**; record `file:line`. Three separate assertions: the walk is wide enough (file floor + it reaches `src/app`), the collection is non-empty, and its distinct files equal the owner. Failure messages list every violation and name `OWNER` as the remedy for a move. Header states detection semantics, blind spots **and** the over-matching direction.
- [x] `pwa/tests/password-input-adoption.test.ts` — exercise the collector against in-memory sources (`ts.createSourceFile`): seven detected forms, six ignored ones.
- [x] `pwa/tests/data-testid-uniqueness.test.ts` — match `toggleTestId` alongside `data-testid`. It is a complete QA address reaching the DOM as one; `testId`/`testIdPrefix` stay out because they carry surface prefixes shared on purpose (measured: five such values).
- [x] `pwa/tests/components/ui/PasswordInput.test.tsx` — re-pin the default as masked; keep `defaultRevealed`; add the mask→reveal→mask round trip, the icon swap, and the text-assist attributes with their override.
- [x] `pwa/tests/app/(auth)/loginForm.test.tsx` — forward the arguments in the container mock, add the round-trip case, pin that the toggle mutates the same node, and add a submit made while the field is revealed.
- [x] `pwa/src/components/ui/PasswordInput.tsx` — end a reveal when the value is submitted, by listening on the enclosing form (the only place that knows a submission was attempted) while something is revealed. The caller's ref is merged with an internal one so RHF keeps owning the field.
- [x] `pwa/CLAUDE.md` and root `CLAUDE.md` — record the adoption rule and the gate.

**Acceptance Criteria:**

- Given the login page, when it first paints, then the password field is masked and the reveal toggle is present with `aria-pressed="false"`.
- Given a typed password and two toggle clicks, when the form submits, then the login port receives that exact string — the falsifier for the `<Input>` → `<PasswordInput>` composition change.
- Given a user who submits while the field is revealed, when the form submits, then the port receives the same value.
- Given a developer who plants any detected form in a `pwa/src` file, when the suite runs, then the gate fails naming that file and line, and every other violation in the same run.
- Given `PasswordInput.tsx` deleted or stripped of its password type, when the suite runs, then the **non-empty** assertion fails. Given it **renamed or moved**, the non-empty assertion still passes and the **ownership** assertion fails instead, naming the new path and pointing at `OWNER` — the two directions are not interchangeable and the failure messages say which is which.
- Given a narrowed `SRC_ROOT` or a skip inside the walk, when the suite runs, then the universe assertion fails — a green may not be bought by looking at less.
- Given a revealed field, when the form is submitted — accepted or rejected — then the field returns to masked and the typed value survives, so a retry does not sit in the clear.
- Given a revealed field, when the browser applies spell-check, autocorrect or autocapitalisation, then it does not, because the component declares all three off.
- Given the existing e2e login/logout specs unchanged, when they run, then they still pass.

## Spec Change Log

- **Trigger:** external review of the draft (five findings) before implementation. **Amended:** the walker's semantics were stated precisely, the failure message was required to list every `file:line`, the two invariants were split, in-memory fixtures were required, and the explicit `defaultRevealed={false}` at the login call site was recorded as intentional. **Known-bad state avoided:** a gate whose contract nobody could read off the file, and a login test that proved presence rather than value. **KEEP:** the masked default and its no-op argument; the gate's absence of any exemption list.
- **Trigger:** three review layers on the implementation (Blind Hunter, Edge Case Hunter, Acceptance Auditor). **Amended:** the component now sets the three text-assist attributes and hides `::-ms-reveal`; the gate reads `type` case-insensitively, descends inline spread objects, walks `.js`/`.jsx`, asserts its universe independently of the positives, and documents its over-matching direction; the uniqueness gate learned `toggleTestId`; the tests gained the icon swap, the aria-pressed round trip, the node-identity pin and a revealed submit; the call-site count was corrected. **Known-bad state avoided:** a gate that stayed green over 8.6% of the tree if `SRC_ROOT` were narrowed; a revealed password uploaded to a cloud spell checker; a record claiming a rename lands on the non-empty assertion when it does not. **KEEP:** the separate assertions, the fixture pairs, and the measured refusal to widen the uniqueness gate to `testId`/`testIdPrefix`.
- **Not amended, because it sits inside `<frozen-after-approval>`:** the I/O matrix's two gate rows enumerate five detected and four ignored forms. Implementation added `type="Password"` and the inline spread object to the first, and the named-object spread and the concatenated value to the second. The rows are now incomplete rather than wrong; the authoritative list is the gate's own header and its fixtures.

## Design Notes

The default flip is a no-op today and that is the point of doing it now: **seven call sites across six files** (`ChangePasswordForm` has two) pass the prop explicitly, so no rendered behaviour moves — only the direction the *next* omission fails in. Counted against the tree, not assumed.

The gate's promise is deliberately narrow and its name says so: this is an **adoption/ownership** property, not password security. Its blind spots and its false-positive direction are both enumerated in its header, because a header that lists only the misses is read as a complete account of the limits and is not one.

Re-masking on submit is the component's job for the same reason, and the alternative was measured against the tree rather than assumed: the product already answered this question once for a *lesser* secret — `IbanCell` re-masks an IBAN after 10s, on mouse-leave and on focus-out — while a login password had no containment at all. A controlled `revealed`/`onRevealChange` API was declined: one consumer needs it, which is the Rule of Three against it, and it would leave the other seven call sites uncovered.

The text-assist attributes belong to the component rather than the call site for the same reason the masked default does: the component is the only thing that knows `type` can become `text`. Placing them ahead of the spread keeps `RecoveryRedeemForm`'s explicit copy authoritative.

## Verification

**Commands:**

- `make pwa.test.unit c='password-input-adoption PasswordInput loginForm'` — expected: pass.
- `make pwa.test.unit` — expected: full unit suite green, exit 0.
- `make pwa.quality` — expected: ESLint + dependency-cruiser + Prettier + tsc clean, exit 0.
- Falsifications, each restored by copying the backed-up bytes (never `git checkout`): plant a password input in a second file (gate names it); strip the owner's password type (non-empty assertion); rename the owner (ownership assertion, not the non-empty one); narrow `SRC_ROOT` to `src/components` (universe assertion); drop `{...register("password")}`; corrupt only the submitted value with `.slice(0, 4)` (exactly the round-trip case fails).

**Manual checks:**

- Log in at `https://localhost/login`: masked by default, the eye icon reveals and re-masks, credentials still authenticate.

## Suggested Review Order

**The policy the component now owns**

- Start here: everything the login form was missing lives in one place.
  [`PasswordInput.tsx:46`](../../pwa/src/components/ui/PasswordInput.tsx#L46)

- The reveal ends at submit; the caller's ref is merged so RHF keeps the field.
  [`PasswordInput.tsx:73`](../../pwa/src/components/ui/PasswordInput.tsx#L73)

- A revealed secret must not be treated as prose by the browser.
  [`PasswordInput.tsx:89`](../../pwa/src/components/ui/PasswordInput.tsx#L89)

**The adoption**

- The one-line reason this story existed.
  [`LoginForm.tsx:119`](../../pwa/src/app/(auth)/_components/LoginForm.tsx#L119)

**The guardrail**

- The invariant, and the only name to change if the component moves.
  [`password-input-adoption.test.ts:51`](../../pwa/tests/password-input-adoption.test.ts#L51)

- Refuses a green bought by looking at less of the tree.
  [`password-input-adoption.test.ts:154`](../../pwa/tests/password-input-adoption.test.ts#L154)

- `toggleTestId` is a complete QA address; surface prefixes deliberately are not.
  [`data-testid-uniqueness.test.ts:38`](../../pwa/tests/data-testid-uniqueness.test.ts#L38)

**Tests worth reading**

- The falsifier for the composition: the value must reach the port intact.
  [`loginForm.test.tsx:170`](../../pwa/tests/app/(auth)/loginForm.test.tsx#L170)

- A rejected sign-in must not leave the password on screen.
  [`loginForm.test.tsx:226`](../../pwa/tests/app/(auth)/loginForm.test.tsx#L226)
