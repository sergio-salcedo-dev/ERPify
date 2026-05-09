import { inject, injectable } from "inversify";
import type { BankRepository } from "../domain/BankRepository";

@injectable()
export class DeleteBank {
  constructor(@inject("BackOfficeBankRepository") private readonly repository: BankRepository) {}

  async run(id: string): Promise<void> {
    return this.repository.delete(id);
  }
}
