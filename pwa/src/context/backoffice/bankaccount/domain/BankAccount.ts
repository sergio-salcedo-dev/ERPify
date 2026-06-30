/**
 * Currency code the API serializes for a bank account (`Currency` enum value).
 * Only EUR exists today; kept as a closed union so a new code is a typed
 * change, not a silent string.
 */
export type BankAccountCurrency = "EUR";

/**
 * Account lifecycle as the API emits it under `status`: the enum's identity
 * value in SCREAMING_SNAKE (`BankAccountStatus->value`), never a display label.
 * Human-readable text is the presentation layer's job (see `STATUS_LABEL` in
 * `BankAccountsTable`).
 */
export type BankAccountStatus = "ACTIVE" | "INACTIVE" | "CLOSED";

export interface BankAccountPrimitives {
  id: string;
  holderName: string;
  iban: string;
  bic: string | null;
  alias: string | null;
  currency: BankAccountCurrency;
  status: BankAccountStatus;
  /**
   * Owning bank id. Absent from the nested list projection (it is the route, not
   * payload) and present on the detail/write resource — hence optional.
   */
  bankId?: string;
  /** ISO-8601 audit timestamps — only the detail/write resource carries them. */
  createdAt?: string;
  updatedAt?: string;
}

/**
 * Read-side view of a bank account. The `iban` is the canonical, integral value
 * straight from the API — it is financial PII and must only ever be masked at
 * the presentation edge (see `IbanCell`), never logged or persisted client-side.
 *
 * `bankId` / `createdAt` / `updatedAt` are optional because the nested list
 * projection omits them (the list is scoped by the route's bank id); the
 * detail/write resource populates all three.
 */
export class BankAccount {
  constructor(
    public readonly id: string,
    public readonly holderName: string,
    public readonly iban: string,
    public readonly bic: string | null,
    public readonly alias: string | null,
    public readonly currency: BankAccountCurrency,
    public readonly status: BankAccountStatus,
    public readonly bankId?: string,
    public readonly createdAt?: string,
    public readonly updatedAt?: string,
  ) {}

  static fromPrimitives(data: BankAccountPrimitives): BankAccount {
    return new BankAccount(
      data.id,
      data.holderName,
      data.iban,
      data.bic,
      data.alias,
      data.currency,
      data.status,
      data.bankId,
      data.createdAt,
      data.updatedAt,
    );
  }
}
