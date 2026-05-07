import { Bank } from "./Bank";

export interface BankInput {
  name: string;
  shortName: string;
}

export interface BankSearchPage {
  banks: Bank[];
  nextCursor?: string;
}

export interface BankRepository {
  search(): Promise<BankSearchPage>;
  find(id: string): Promise<Bank>;
  create(input: BankInput): Promise<Bank>;
  update(id: string, input: BankInput): Promise<Bank>;
  delete(id: string): Promise<void>;
}
