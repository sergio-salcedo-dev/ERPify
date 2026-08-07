import { inject, injectable } from "inversify";
import type { RevokeInvitationRepository } from "../domain/RevokeInvitationRepository";

/**
 * Withdraws the pending invitation of an identity through its own port, kept separate from the lifecycle
 * transition and from the erasure so a withdrawal is an explicit intent: it retires a live accept token and
 * leaves the identity at `REVOKED`, it does not remove the person. Resolves to `void` — the API hands back no
 * resource, so the caller reconciles by re-reading the register.
 */
@injectable()
export class RevokeInvitation {
  constructor(
    @inject("BackOfficeRevokeInvitationRepository")
    private readonly repository: RevokeInvitationRepository,
  ) {}

  async run(userId: string): Promise<void> {
    return this.repository.revoke(userId);
  }
}
