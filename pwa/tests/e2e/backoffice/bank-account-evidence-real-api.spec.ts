import { randomInt } from "node:crypto";
import { readFile } from "node:fs/promises";
import { test, expect, type APIRequestContext } from "../fixtures/authenticatedTest";
import { E2E_USER_PASSWORD, VIEWPORT_DESKTOP } from "../constants";
import {
  createApiContext,
  createBank,
  deleteBank,
  uniqueRunPrefix,
} from "../fixtures/banks-real-api";

const BANK_ACCOUNTS_PATH = "/api/v1/backoffice/bank-accounts";
const DOCUMENTS_PATH = "/api/v1/documents";
const CONTENT_ROUTE = /\/api\/v1\/documents\/uploads\/[^/?]+\/content\?/;

/**
 * Real-API E2E for bank-account evidence: an ADMIN files an original from the account's detail
 * screen, through the declare and the raw-body PUT the Documents upload is, attaches it, reads it
 * back once the scan has passed it, and detaches it. The one leg no unit or functional test sees is
 * the browser's own request — that the PUT carries exactly the file's bytes, its exact
 * `Content-Length` and the NFC filename in its query — and whether the PWA decodes what the API
 * answers at each step.
 *
 * Needs the full dev stack (`make docker.up.wait`): ingest and read are open in dev, and the dev
 * stack binds a scanner that answers clean, so the minute sweep moves the document to `ready`. The
 * document is erased through the API afterwards (password re-proved), then the account closed and
 * deleted, so the run leaves an erased tombstone and nothing readable behind.
 */
test.describe.configure({ mode: "serial" });

/** A valid German IBAN for a random account number, so parallel runs never collide on it. */
function randomIban(): string {
  const bban = `37040044${String(randomInt(0, 10_000_000_000)).padStart(10, "0")}`;
  // Move the country code and "00" to the end, letters as numbers (D=13, E=14), then mod 97.
  const numeric = `${bban}131400`;
  let remainder = 0;
  for (const digit of numeric) remainder = (remainder * 10 + Number(digit)) % 97;
  return `DE${String(98 - remainder).padStart(2, "0")}${bban}`;
}

const FILENAME = "Certificado de titularidad é & co.txt";
const BYTES = Buffer.alloc(64 * 1024, "evidence ");

test.describe("BackOffice - Bank account evidence (real API)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  const runPrefix = uniqueRunPrefix("Evidence");
  let api: APIRequestContext;
  let bankId: string;
  let accountId: string;
  let documentId: string | undefined;

  test.beforeAll(async ({ workerStorageState }) => {
    api = await createApiContext(workerStorageState);
    const bank = await createBank(api, `${runPrefix} Bank`, runPrefix.slice(-40));
    bankId = bank.id;
    const response = await api.post(BANK_ACCOUNTS_PATH, {
      data: {
        bankId,
        holderName: `${runPrefix} Holder`,
        iban: randomIban(),
        bic: null,
        alias: null,
        currency: "EUR",
      },
      headers: { "Content-Type": "application/json" },
    });
    if (!response.ok()) {
      throw new Error(
        `POST ${BANK_ACCOUNTS_PATH} failed (${response.status()}): ${await response.text()}`,
      );
    }
    accountId = ((await response.json()) as { data: { id: string } }).data.id;
  });

  test.afterAll(async () => {
    // Best effort, each step independent: a failed erase must not skip the account's removal.
    const steps: [string, () => Promise<unknown>][] = [];
    if (documentId !== undefined) {
      const id = documentId;
      steps.push([
        `erase document ${id}`,
        () =>
          api.post(`${DOCUMENTS_PATH}/${id}/erase`, {
            data: { reason: "uploaded_in_error", currentPassword: E2E_USER_PASSWORD },
            headers: { "Content-Type": "application/json" },
          }),
      ]);
    }
    steps.push(
      [
        `close account ${accountId}`,
        () =>
          api.patch(`${BANK_ACCOUNTS_PATH}/${accountId}/status`, {
            data: { status: "CLOSED" },
            headers: { "Content-Type": "application/json" },
          }),
      ],
      [`delete account ${accountId}`, () => api.delete(`${BANK_ACCOUNTS_PATH}/${accountId}`)],
      [`delete bank ${bankId}`, () => deleteBank(api, bankId)],
    );
    for (const [label, step] of steps) {
      await step().catch((error: unknown) => {
        console.warn(`[bank-account-evidence-real-api] ${label} failed:`, error);
      });
    }
    await api.dispose();
  });

  test("files, attaches, reads back and detaches an original through the real stack", async ({
    page,
  }) => {
    // Holds the PUT a moment so the in-flight state is observable, then lets it through untouched.
    await page.route(CONTENT_ROUTE, async (route) => {
      await new Promise((resolve) => setTimeout(resolve, 1_000));
      await route.continue();
    });

    await page.goto(`/backoffice/bank-accounts/${accountId}`);
    await expect(page.getByTestId("bank-account-evidence__empty")).toBeVisible({ timeout: 15_000 });

    const putRequest = page.waitForRequest(
      (request) => request.method() === "PUT" && CONTENT_ROUTE.test(request.url()),
    );
    const putResponse = page.waitForResponse(
      (response) => response.request().method() === "PUT" && CONTENT_ROUTE.test(response.url()),
    );
    await page.getByTestId("bank-account-evidence__input").setInputFiles({
      name: FILENAME,
      mimeType: "text/plain",
      buffer: BYTES,
    });

    await expect(page.getByTestId("bank-account-evidence__progress")).toBeVisible();
    await expect(page.getByTestId("bank-account-evidence__cancel")).toBeVisible();

    const request = await putRequest;
    const headers = await request.allHeaders();
    expect(headers["content-length"]).toBe(String(BYTES.length));
    expect(headers["content-type"]).toBe("application/octet-stream");
    expect(request.postDataBuffer()?.equals(BYTES)).toBe(true);
    const query = new URL(request.url()).search;
    expect(query).toBe(`?filename=${encodeURIComponent(FILENAME.normalize("NFC"))}`);

    const filed = (await (await putResponse).json()) as { data: { id: string } };
    documentId = filed.data.id;

    const items = page.locator('[data-testid^="bank-account-evidence__item-"]');
    await expect(items).toHaveCount(1);
    await expect(items.first()).toContainText(FILENAME);
    await expect(page.getByTestId("bank-account-evidence__upload")).toHaveCount(0);
    const linkId = (await items.first().getAttribute("data-testid"))?.replace(
      "bank-account-evidence__item-",
      "",
    );
    expect(linkId).toMatch(/^[0-9a-f-]{36}$/);

    // The dev scanner answers clean on the minute sweep; the list reports it on the next load.
    const state = page.getByTestId(`bank-account-evidence__state-${linkId}`);
    await expect(async () => {
      await page.reload();
      await expect(state).toHaveText("Ready", { timeout: 5_000 });
    }).toPass({ timeout: 180_000, intervals: [5_000] });

    const downloadEvent = page.waitForEvent("download");
    await page.getByTestId(`bank-account-evidence__download-${linkId}`).click();
    const download = await downloadEvent;
    expect(download.suggestedFilename()).toBe(FILENAME);
    const saved = await download.path();
    expect((await readFile(saved)).equals(BYTES)).toBe(true);

    await page.getByTestId(`bank-account-evidence__detach-${linkId}`).click();
    await page.getByTestId(`bank-account-evidence__detach-confirm-${linkId}`).click();
    await expect(page.getByTestId("bank-account-evidence__empty")).toBeVisible();
    await expect(page.getByTestId("bank-account-evidence__status")).toContainText("detached");
  });
});
