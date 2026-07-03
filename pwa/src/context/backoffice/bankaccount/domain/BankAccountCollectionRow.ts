import type { PageEnvelope } from "@/context/shared/search/domain";
import type { BankAccountCurrency, BankAccountStatus } from "./BankAccount";

/**
 * Read-side projection of one row in the cross-bank account collection
 * (`GET /bank-accounts`). It is deliberately NOT the {@link BankAccount} entity:
 * each row also carries its owning bank's identity (`bankName`/`bankShortName`),
 * composed by the server-side JOIN, because the bank is not the route here. That
 * read-composition data belongs to another aggregate (Bank), so it lives on this
 * projection rather than polluting the account entity (ADR D3 — read-side
 * projections). It mirrors the API's `BankAccountCollectionResource`.
 *
 * The `iban` is the canonical, integral value straight from the API — financial
 * PII that must only ever be masked at the presentation edge (see `IbanCell`),
 * never logged or persisted client-side. There are no audit timestamps: the
 * collection view drops them (they are keyset paging machinery, not wire data).
 */
export interface BankAccountCollectionRow {
  id: string;
  bankId: string;
  bankName: string;
  bankShortName: string;
  holderName: string;
  iban: string;
  bic: string | null;
  alias: string | null;
  currency: BankAccountCurrency;
  status: BankAccountStatus;
}

/**
 * One page of the global collection plus the cursor-only pagination envelope.
 * Items travel in `rows`; `hasNext`/`hasPrev`/`count` and the verbatim
 * navigation `links` come from {@link PageEnvelope}. There is no page number and
 * no raw cursor.
 */
export type BankAccountCollectionPage = { rows: BankAccountCollectionRow[] } & PageEnvelope;
