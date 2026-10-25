import { inject, injectable } from "inversify";
import type { ResendInvitationRepository } from "../domain/ResendInvitationRepository";

/**
 * Re-delivers the pending invitation of an identity with a fresh link through its own port, kept apart from
 * the withdrawal so the two intents never share a call: one keeps the person pending with a working link, the
 * other ends the invitation for good. Resolves to `void` — the API hands back no resource.
 */
@injectable()
export class ResendInvitation {
  constructor(
    @inject("BackOfficeResendInvitationRepository")
    private readonly repository: ResendInvitationRepository,
  ) {}

  async run(userId: string): Promise<void> {
    return this.repository.resend(userId);
  }
}
