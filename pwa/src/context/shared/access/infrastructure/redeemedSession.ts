/**
 * True from the moment a recovery-secret redemption opens the current session until that session ends or
 * another one starts: the session provider's `login()` and `logout()` clear it, so a sign-in through any other
 * route (password, reset, invitation) never inherits it. Module memory only — never storage or a URL.
 */
let redeemedSession = false;

export function markSessionRedeemed(): void {
  redeemedSession = true;
}

export function isSessionRedeemed(): boolean {
  return redeemedSession;
}

export function clearSessionRedeemed(): void {
  redeemedSession = false;
}
