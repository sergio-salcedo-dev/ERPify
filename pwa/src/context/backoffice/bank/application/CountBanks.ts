import { inject, injectable } from "inversify";
import type { BankCountReader } from "../domain/BankCountReader";

@injectable()
export class CountBanks {
  constructor(@inject("BackOfficeBankCountReader") private readonly reader: BankCountReader) {}

  async run(): Promise<number> {
    return this.reader.read();
  }
}
