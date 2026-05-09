import { inject, injectable } from "inversify";
import type { Bank } from "../domain/Bank";
import type { BankInput, BankRepository } from "../domain/BankRepository";

@injectable()
export class CreateBank {
  constructor(@inject("BackOfficeBankRepository") private readonly repository: BankRepository) {}

  async run(input: BankInput): Promise<Bank> {
    return this.repository.create(input);
  }
}
