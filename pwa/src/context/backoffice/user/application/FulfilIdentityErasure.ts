import { inject, injectable } from "inversify";
import type { EraseIdentityRepository } from "../domain/EraseIdentityRepository";

/**
 * Fulfils a GDPR "right to erasure" for an identity through the dedicated destructive port, kept separate from
 * any lifecycle transition so an erasure is an explicit, irreversible intent. Resolves to `void` — the identity
 * is gone, so there is nothing to reflect in place; the caller redirects away from the absent detail.
 */
@injectable()
export class FulfilIdentityErasure {
  constructor(
    @inject("BackOfficeEraseIdentityRepository")
    private readonly repository: EraseIdentityRepository,
  ) {}

  async run(id: string): Promise<void> {
    return this.repository.erase(id);
  }
}
