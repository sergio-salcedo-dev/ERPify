# Story RR-2: "Evict first, rotate second" on every surface that tells a user what to do next

Status: backlog — **blocked on #1001** (redemption evicting every other session is what the redemption
copy states)

## Story

As the owner of an account someone else may be signed in to,
I want every place that tells me what to do next to put signing the others out before changing the
password,
so that I stop feeding a new password to a session that is still open.

## Why

§7 owes "ordering guidance — evict first, rotate second — in the UI copy, the password-changed mail and
the redemption flow". Today only the account-locked mail carries it (`SymfonyAccountLockedEmailSender`:
"…review your active sessions and revoke the others first, then change your password."). After #1001 the
redemption itself evicts, so its guidance becomes the *second* half: set a new password, mint a new
secret.

What the system does, checked before writing copy (so no line claims more):
- Redemption (after #1001): evicts every other session **atomically** with the consumption (ADR D10).
- Password reset: clears the lockout and revokes every session after commit (`CompletePasswordReset`, best
  effort, backed by the credential change de-authenticating them natively).
- Password change: needs the current password; asks the other sessions to sign out, best effort.
- Redemption changes no password: whoever knows it can still sign in.

## Acceptance criteria

1. **Password-changed mail** (`SymfonyPasswordChangedEmailSender`, text and HTML), between the two
   existing lines, keeping "contact {from}" as the fallback:
   "If this was not you, use your recovery secret or reset your password from the sign-in page — **either
   one signs every other device out**. Then set a new password."
   The docblock's refusal to claim sessions were closed by *this* change still holds.
2. **`ChangePasswordForm`** hint above the fields: "Think someone else is signed in? Sign out other devices
   first in **Active sessions**, then change your password here." (link to `/backoffice/profile/sessions`)
3. **After redemption** (`(auth)/_components/RecoveryRedeemForm.tsx`): the toast becomes "Signed in with
   your recovery secret. We've signed out your other sessions." and the redirect target shows once:
   "Next: set a new password — use Forgot password if you no longer know it — then create a new recovery
   secret. The one you used is spent." RR-1's banner then appears on its own.
4. **§7 corrected** in the same PR: the clause "the redemption flow, whose first act has to be
   `revoke-others`" is stale after #1001 and is rewritten to what redemption now does.
5. **Tests.** A PHPUnit assertion on both mail bodies; Vitest on the form hint and the post-redemption
   copy; `ui-copy-language` green.

## Security notes

The copy must never suggest the account is secure after redemption alone — the password is unchanged.
No behaviour changes; copy and one docs correction.

## Source

As RR-1, plus the review of the draft copy by Sally (UX), 2026-09-28.
