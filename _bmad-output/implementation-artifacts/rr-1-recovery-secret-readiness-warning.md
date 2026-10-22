# Story RR-1: An administrator without a live recovery secret is told so on every back-office page

Status: ready-for-dev

## Story

As an administrator of an ERPify installation,
I want the back office to tell me, wherever I am, that my account holds no live recovery secret,
so that I mint one — and re-mint after every redemption — before an attacker can use the lockout
composition of #602 against an account with no way back in.

## Why

`PRODUCTION_SECURITY_CHECKLIST.md` §7 (*A stolen session can deny the owner a credential rotation, but not
an eviction*) and the watched-risk row of #602 make this the product's debt **before the first customer**:
get every administrator to mint a secret, re-mint after each redemption, and warn while none is live. None
of it exists. Measured on `main` (2026-09-28):

- `GET /me/recovery-secret` answers `{exists, mintedAt, expiresAt}`; "live" is not a notion the API has.
  The rule *a lapsed row counts as none* lives only in the PWA (`hasLapsed` in `RecoverySecretPanel.tsx`).
- An expired row still occupies the slot: minting over it answers 409 `recovery-secret-already-exists`
  until it is revoked. **Kept as is (decision, 2026-09-28)** — with a ten-year TTL (#870) the case is rare,
  and the copy guides "revoke, then create" instead of amending ADR D7.
- The only surface is `/backoffice/profile`; with no secret the panel renders the mint form and nothing
  else. Nothing outside that page says anything.

Redemption deletes the row, so "re-mint after each redemption" needs no logic of its own: the warning
below appears by itself on the next page.

## Acceptance criteria

1. **API — the rule lives on the server, once.** `RecoverySecret::isLiveAt(DateTimeImmutable $now): bool`
   (row not expired) and `RecoverySecretResource` gains `live: bool` — `false` when no row exists or the
   row has lapsed. `exists`, `mintedAt`, `expiresAt` are unchanged. No new route, so
   `api/.route-manifest.json` and `api/.credential-proof-policy` do not move. Behat pins the three states
   (none / live / expired, the last built under a clock set past `expiresAt`).
2. **PWA — the panel reads `live`.** `RecoverySecretPanel` derives the Active/Expired state from `live`
   instead of `hasLapsed`; `hasLapsed` is deleted if nothing else uses it.
3. **PWA — shell banner, administrators only.** In `BackOfficeLayoutClient`, above `<main>`, rendered when
   the caller holds `ADMIN` (from the server-side role the shell already receives, never a client guess)
   and `live === false`. **Not dismissible.** Markup: `<aside aria-label="Account recovery">` — a landmark,
   **not** a live region (it mounts on every page; `role="status"`/`<output>`/`aria-live` would
   re-announce it on every navigation, and `pwa/tests/live-region-surfaces.test.ts` stays untouched). A
   small `NoticeBanner` in `components/erpify` (tone + text + link), reusing `StatusBadge`'s warning dot.
   Copy (Sally):
   - no secret: "Your account has no recovery secret. If you are ever locked out, it is how you get back
     in." — link "Create one in Profile → Recovery secret"
   - expired: "Your recovery secret has expired and no longer signs you in. Revoke it, then create a new
     one." — link "Go to Profile → Recovery secret"
4. **PWA — panel copy.**
   - empty state, above the mint form: "You don't have a recovery secret yet. Create one now, while you
     can still sign in."
   - expired: "This recovery secret has expired and no longer signs you in. Revoke it, then create a new
     one. An account holds one secret at a time."
   - above the `MutationError` on a 409: "You already have a recovery secret. To replace it, revoke it
     first."
5. **Tests.** Vitest: banner absent for a non-admin; absent for an admin with `live: true`; each variant
   for an admin with `live: false`; no live-region role. Playwright (real API): an administrator with no
   secret sees the banner on two different back-office routes, mints, and it disappears.
6. **Gates.** `ui-copy-language`, `backoffice-route-titles`, `live-region-surfaces` green; `make
   php.quality`, `make pwa.quality`.

## Security notes

- The banner reads only the caller's own row through the caller's own session: it discloses nothing to a
  third party. `live` is derived from fields the response already carries.
- It must not become a prompt a stolen session can exploit: minting and revoking still re-prove the
  password (ADR D7/D8); nothing here relaxes that.
- A non-administrator may still mint (any `ACTIVE` identity can); the banner simply does not nag them.

## Out of scope

#602's residuals (i)–(iii) and #870 (the ten-year TTL). #602 stays open as an accepted risk after this
epic: the epic meets its product trigger, not the risk.

## Source

`gh issue view 602` (Estado block), `PRODUCTION_SECURITY_CHECKLIST.md` §7 and its watched-risk table,
`docs/adr/administrative-recovery-channel.md` D7–D10.
