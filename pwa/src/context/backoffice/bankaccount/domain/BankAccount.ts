/**
 * The two wire vocabularies of a bank account, declared once for the whole PWA and derived
 * everywhere else — the payload guard's `Set`, the form's Zod enum and the status control's options
 * all read these arrays rather than restating them. A vocabulary written in more than one place
 * drifts in the direction nothing checks: adding a member to a second copy is invisible to the
 * compiler, because a narrower array is assignable wherever the wider union is expected.
 *
 * They live in `domain/` because dependencies point inward: the schema and the adapter may read
 * them, and the reverse would make the domain depend on its own consumers.
 *
 * The server enum is the authority over both. `EnumWireContractGateTest` (API side) compares these
 * arrays against `Currency` and `BankAccountStatus` and fails the build on divergence, so a value
 * added on one deployable and not the other cannot ship.
 */

/** Currency code the API serializes under `currency` (`Currency` enum value). */
export const BANK_ACCOUNT_CURRENCIES = ["EUR"] as const;

export type BankAccountCurrency = (typeof BANK_ACCOUNT_CURRENCIES)[number];

/**
 * Account lifecycle as the API emits it under `status`: the enum's identity value in
 * SCREAMING_SNAKE (`BankAccountStatus->value`), never a display label. Human-readable text is the
 * presentation layer's job (see `STATUS_LABEL` in `BankAccountsTable`).
 */
export const BANK_ACCOUNT_STATUSES = ["ACTIVE", "INACTIVE", "CLOSED"] as const;

export type BankAccountStatus = (typeof BANK_ACCOUNT_STATUSES)[number];

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
