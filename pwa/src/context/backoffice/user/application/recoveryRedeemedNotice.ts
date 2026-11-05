/**
 * The one-shot signal that this tab has just redeemed a recovery secret, read by the dashboard to
 * show the next steps once. It lives in module memory on purpose: the redemption hands over with a
 * client-side `router.push`, which keeps the module, while a reload empties it — which is exactly
 * "once". A query string would land in history, the `Referer` header and the access log, and web
 * storage would outlive the tab's own navigation; neither may carry it.
 *
 * Reading and clearing are separate so a component can read during render and clear in an effect:
 * StrictMode mounts twice in development, and a read that consumed would leave the second mount
 * without the notice. The server never marks it — only the browser's submit handler does — so its
 * copy of this module always answers `false`.
 */
let recoveryRedeemed = false;

/**
 * Whether THIS document's session was opened by redeeming a recovery secret, for as long as the document
 * lives. The notice flag above is one-shot and the dashboard clears it on first paint; this one answers a
 * different question, "was the password involved in getting in?", and has to outlive that clearing.
 *
 * The back office reads it to stand down its enrolment step: the secret just redeemed is spent, so the
 * account holds none, but minting another proves the current password and the owner who came in with a
 * secret may not have it. Blocking them there would lock the very person the redemption just let in out of
 * the one place that can repair the account.
 *
 * Module memory, like the flag above: it is empty on a server render, survives the client-side hand-over from
 * `/recovery`, and is never written to storage or a URL. A password sign-in clears it, and a reload empties
 * it — the owner then meets the enrolment step, whose sign-out and Forgot password route is the way through
 * for someone who does not know the password. The failure direction is the stricter one, never a bypass.
 */
let recoveryRedeemedSession = false;

export function markRecoveryRedeemed(): void {
  recoveryRedeemed = true;
  recoveryRedeemedSession = true;
}

export function peekRecoveryRedeemed(): boolean {
  return recoveryRedeemed;
}

export function clearRecoveryRedeemed(): void {
  recoveryRedeemed = false;
}

export function isRecoveryRedeemedSession(): boolean {
  return recoveryRedeemedSession;
}

export function clearRecoveryRedeemedSession(): void {
  recoveryRedeemedSession = false;
}
