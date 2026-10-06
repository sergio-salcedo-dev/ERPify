/**
 * The signed-in identity could not be resolved because the server answered that a dependency it
 * needs to decide the request is unreachable (503 `service-unavailable`). It is the third answer
 * {@link IdentityRepository.me} can give, and it is deliberately not folded into the other two: an
 * identity means "signed in", `null` means "no live session", and this means "the server could
 * not tell" — so the route guard sends a back-office visitor to the maintenance page instead of to
 * a sign-in form that would most likely answer the same outage.
 *
 * It does not name the dependency, because the answer does not either: `service-unavailable` is
 * the API's generic 503, shared by every dependency outage it reports. An unreachable session
 * store is the common case on `/me`, raised by the admission gate before any controller runs, but
 * it is not the only one.
 *
 * A transport failure or a body that does not parse is NOT this error: neither proves the server
 * answered at all, so those keep propagating as whatever the transport raised.
 */
export class IdentityServiceUnavailableError extends Error {
  constructor() {
    super("The identity service is temporarily unavailable.");
    this.name = "IdentityServiceUnavailableError";
  }
}
