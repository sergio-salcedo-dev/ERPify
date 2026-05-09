import { inject, injectable } from "inversify";
import type { Bank } from "../domain/Bank";
import type { BankInput, BankRepository } from "../domain/BankRepository";

@injectable()
export class UpdateBank {
  constructor(@inject("BackOfficeBankRepository") private readonly repository: BankRepository) {}

  async run(id: string, input: BankInput): Promise<Bank> {
    return this.repository.update(id, input);
  }
}
