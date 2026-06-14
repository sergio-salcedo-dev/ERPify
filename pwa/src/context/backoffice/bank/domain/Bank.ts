export interface BankPrimitives {
  id: string;
  name: string;
  shortName: string;
  createdAt: string;
  updatedAt: string;
  accountCount: number;
}

export class Bank {
  constructor(
    public readonly id: string,
    public readonly name: string,
    public readonly shortName: string,
    public readonly createdAt: string,
    public readonly updatedAt: string,
    public readonly accountCount: number,
  ) {}

  static fromPrimitives(data: BankPrimitives): Bank {
    return new Bank(
      data.id,
      data.name,
      data.shortName,
      data.createdAt,
      data.updatedAt,
      data.accountCount,
    );
  }
}
