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
}

/**
 * Read-side view of a bank account. The `iban` is the canonical, integral value
 * straight from the API — it is financial PII and must only ever be masked at
 * the presentation edge (see `IbanCell`), never logged or persisted client-side.
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
    );
  }
}
