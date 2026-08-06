/**
 * Identity-shaped write port for withdrawing a pending invitation, the counterpart of
 * {@link InviteUserRepository}: an invitation that should never have been sent is withdrawn through its own use
 * case, never the generic `CrudRepository.delete()` — identity administration is not CRUD, and nothing is
 * deleted here: the token stops being redeemable and the identity it provisioned moves to the terminal
 * `REVOKED` state, so the register keeps showing the person, no longer as pending.
 *
 * Keyed by the invited user's id, not by an invitation id: the console reads identities, never invitations, so
 * the invitation id is knowledge it does not hold. Resolves to `void` — the API answers 204 with no body, and
 * a user with nothing revocable is a 404, not an empty success.
 */
export interface RevokeInvitationRepository {
  revoke(userId: string): Promise<void>;
}
