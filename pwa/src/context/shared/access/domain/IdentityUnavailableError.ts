/**
 * The server answered that it cannot decide whether a session is live right now — the session store
 * is down, or a gateway in front of it is. It is neither "signed in" nor "signed out": sending the
 * user to sign in would land them on a form the same outage refuses, so the caller shows the
 * maintenance surface instead.
 *
 * A plain `Error` so the port can say "could not decide" without leaking the transport; the
 * adapter keeps what it observed as `cause`.
 */
export class IdentityUnavailableError extends Error {
  constructor(options?: ErrorOptions) {
    super("The server could not resolve the session", options);
    this.name = "IdentityUnavailableError";
  }
}
