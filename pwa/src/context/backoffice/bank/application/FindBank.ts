import { inject, injectable } from "inversify";
import type { Bank } from "../domain/Bank";
import type { BankRepository } from "../domain/BankRepository";

@injectable()
export class FindBank {
  constructor(@inject("BackOfficeBankRepository") private readonly repository: BankRepository) {}

  async run(id: string): Promise<Bank> {
    return this.repository.find(id);
  }
}
