import { test, expect, type APIRequestContext } from "../fixtures/authenticatedTest";
import { VIEWPORT_DESKTOP } from "../constants";
import {
  createApiContext,
  createBank,
  deleteBank,
  uniqueRunPrefix,
} from "../fixtures/banks-real-api";

const BANK_ACCOUNTS_PATH = "/api/v1/backoffice/bank-accounts";

interface ApiBankAccount {
  id: string;
  bankId: string;
}

async function createBankAccount(
  api: APIRequestContext,
  bankId: string,
  holderName: string,
  iban: string,
): Promise<ApiBankAccount> {
  const response = await api.post(BANK_ACCOUNTS_PATH, {
    data: { bankId, holderName, iban, bic: null, alias: null, currency: "EUR" },
    headers: { "Content-Type": "application/json" },
  });
  if (!response.ok()) {
    throw new Error(
      `POST ${BANK_ACCOUNTS_PATH} failed (${response.status()}): ${await response.text()}`,
    );
  }
  const body = (await response.json()) as { data: ApiBankAccount };
  return body.data;
}

/**
 * Real-API E2E coverage for the audit diff surface and its snapshot-header restoration. Every
 * other audit test in this suite renders from a hand-built `detail` prop; this one instead creates a
 * genuine BankAccount against the live Symfony backend (with `bic`/`alias` left unset) and reads its
 * audit trail back through the real `GET /audit/events/{id}` endpoint — the one leg of the
 * write→capture→seal→serialize→render pipeline that a unit or functional test cannot see, because it
 * stops at the JSON the backend emits and never confirms the PWA decodes it the same way.
 */
test.describe("BackOffice - Audit trail diff (real API)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  const runPrefix = uniqueRunPrefix("AuditDiff");
  let api: APIRequestContext;
  let bankId: string;
  let accountId: string;

  test.beforeAll(async ({ workerStorageState }) => {
    api = await createApiContext(workerStorageState);
    const bank = await createBank(api, `${runPrefix} Bank`, `${runPrefix.slice(-40)}`.slice(0, 50));
    bankId = bank.id;
    const account = await createBankAccount(
      api,
      bankId,
      `${runPrefix} Holder`,
      "DE89370400440532013000",
    );
    accountId = account.id;
  });

  test.afterAll(async () => {
    // Best-effort cleanup, mirroring `deleteBanksSafely`: log a failure to stderr for triage rather
    // than swallowing it outright, so an unexpected cleanup failure doesn't silently leak the row.
    // Each step is caught independently — a PATCH failure must not skip the DELETE attempt that follows.
    // A bank account only hard-deletes from CLOSED (409 otherwise) — close it first.
    await api
      .patch(`${BANK_ACCOUNTS_PATH}/${accountId}/status`, {
        data: { status: "CLOSED" },
        headers: { "Content-Type": "application/json" },
      })
      .catch((err: unknown) => {
        console.warn(`[audit-change-diff-real-api] close failed for account ${accountId}:`, err);
      });
    await api.delete(`${BANK_ACCOUNTS_PATH}/${accountId}`).catch((err: unknown) => {
      console.warn(`[audit-change-diff-real-api] delete failed for account ${accountId}:`, err);
    });
    await deleteBank(api, bankId).catch((err: unknown) => {
      console.warn(`[audit-change-diff-real-api] delete failed for bank ${bankId}:`, err);
    });
    await api.dispose();
  });

  test("renders the never-populated bic/alias fields and an honest CREATE header", async ({
    page,
  }) => {
    await page.goto(`/backoffice/audit?resourceType=BankAccount&resourceId=${accountId}`);

    // A generous timeout on this first assertion, not the others: it also absorbs Next dev's
    // cold-compile of the route on its first hit in a session, which the default 5s can miss.
    const row = page.locator('[data-testid^="audit-timeline__row-"]');
    await expect(row).toHaveCount(1, { timeout: 15_000 });
    // The row's own onClick ignores a click landing on an interactive child (e.g. the correlation-id
    // copy button), by design — so click the leading timestamp cell, never the row's bounding-box
    // center, which can resolve to that button. `force` steps past the realtime "Bank account created"
    // toast this same seed fires, unrelated to what this test verifies.
    await row.locator("td").first().click({ force: true });

    const drawer = page.getByTestId("audit-entry-drawer__diff");
    await expect(drawer).toBeVisible();

    // The real operation (CREATED), not an inference over the rows, exercised end to end against
    // the real backend.
    await expect(page.getByTestId("audit-entry-drawer__diff__snapshot")).toHaveText(
      "Initial state",
    );

    // BankAccount has 10 mapped fields (> COLLAPSE_THRESHOLD), and the two empty ones sort last, so
    // they sit behind the reveal toggle.
    await page.getByTestId("audit-entry-drawer__diff__toggle").click();

    // bic/alias were sent as null and never populated: present as evidence, rendered "Not set",
    // never silently dropped — the #413 fix, now proven against the real backend response.
    await expect(page.getByTestId("audit-entry-drawer__diff__field-bic")).toHaveAttribute(
      "data-kind",
      "empty",
    );
    await expect(page.getByTestId("audit-entry-drawer__diff__field-alias")).toHaveAttribute(
      "data-kind",
      "empty",
    );

    // The operation key that sources the header is excluded from the raw Metadata block.
    await expect(page.getByText(/"operation"/)).not.toBeVisible();
  });
});
