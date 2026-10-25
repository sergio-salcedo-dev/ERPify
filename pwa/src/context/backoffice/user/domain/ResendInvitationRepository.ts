/**
 * Identity-shaped write port for re-delivering a pending invitation, the sibling of
 * {@link RevokeInvitationRepository}: the API mints a fresh accept link, kills the previous one and emails the
 * new one. Nothing about the identity changes — it stays pending — so this is neither a lifecycle transition
 * nor a CRUD update.
 *
 * Keyed by the invited user's id, not by an invitation id: the console reads identities, never invitations.
 * Resolves to `void` — the API answers 204 with no body, because the new link is a credential that travels
 * only in the email; a user with nothing resendable is a 404, and a link whose email could not be sent is a
 * 503, never an empty success.
 */
export interface ResendInvitationRepository {
  resend(userId: string): Promise<void>;
}
