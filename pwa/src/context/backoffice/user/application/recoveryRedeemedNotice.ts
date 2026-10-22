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

export function markRecoveryRedeemed(): void {
  recoveryRedeemed = true;
}

export function peekRecoveryRedeemed(): boolean {
  return recoveryRedeemed;
}

export function clearRecoveryRedeemed(): void {
  recoveryRedeemed = false;
}
