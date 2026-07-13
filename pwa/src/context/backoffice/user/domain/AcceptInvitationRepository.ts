import type { AcceptInvitationCommand } from "./AcceptInvitationCommand";
import type { AcceptInvitationOutcome } from "./AcceptInvitationOutcome";

/**
 * Port for accepting an invitation. The adapter owns the HTTP call — including
 * the stateless CSRF nonce it attaches — and maps the response to a typed
 * {@link AcceptInvitationOutcome}, so callers depend on this domain contract
 * (DIP) and never touch `fetch` / `HttpClient` / status codes.
 */
export interface AcceptInvitationRepository {
  accept(command: AcceptInvitationCommand): Promise<AcceptInvitationOutcome>;
}
