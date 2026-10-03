/**
 * The signed-in identity could not be resolved because the server cannot reach its session
 * store. It is the third answer {@link IdentityRepository.me} can give, and it is deliberately
 * not folded into the other two: an identity means "signed in", `null` means "no live session",
 * and this means "the server could not tell" — so the caller sends the user to the maintenance
 * page instead of to a sign-in form that would answer the same outage.
 *
 * A transport failure or a body that does not parse is NOT this error: neither proves the server
 * answered for its store, so those keep propagating as whatever the transport raised.
 */
export class SessionStoreUnavailableError extends Error {
  constructor() {
    super("The session store is temporarily unavailable.");
    this.name = "SessionStoreUnavailableError";
  }
}
