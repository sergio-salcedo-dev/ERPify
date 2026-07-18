import { inject, injectable } from "inversify";
import type { InviteUserInput, InviteUserRepository } from "../domain/InviteUserRepository";

@injectable()
export class InviteUser {
  constructor(
    @inject("BackOfficeInviteUserRepository") private readonly repository: InviteUserRepository,
  ) {}

  async run(input: InviteUserInput): Promise<void> {
    return this.repository.invite(input);
  }
}
