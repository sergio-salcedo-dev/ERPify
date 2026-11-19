export interface BankPrimitives {
  id: string;
  name: string;
  shortName: string;
  createdAt: string;
  updatedAt: string;
  accountCount: number;
  /** The id of the logo the bank holds, `null` when none. A presence flag and a cache key, never a URL. */
  logoImageId?: string | null;
}

export class Bank {
  constructor(
    public readonly id: string,
    public readonly name: string,
    public readonly shortName: string,
    public readonly createdAt: string,
    public readonly updatedAt: string,
    public readonly accountCount: number,
    public readonly logoImageId: string | null = null,
  ) {}

  static fromPrimitives(data: BankPrimitives): Bank {
    return new Bank(
      data.id,
      data.name,
      data.shortName,
      data.createdAt,
      data.updatedAt,
      data.accountCount,
      data.logoImageId ?? null,
    );
  }
}
