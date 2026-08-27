import type { Page, Route } from "@playwright/test";

/**
 * Mocked-route fixtures for the STANDALONE, cross-bank accounts surface:
 * the global collection list (`GET /bank-accounts`, rows enriched with the
 * owning bank's name via the server JOIN) and the per-account detail
 * (`GET /bank-accounts/{id}`, the full record with `bankId` + timestamps).
 *
 * The realtime channel is faked deterministically: {@link installFakeMercure}
 * swaps `window.EventSource` for a controllable stand-in and {@link emitMercure}
 * pushes a message, so a "reflects live" test never depends on the real Mercure
 * hub (no connect-before-publish race, no 20s timeout).
 */

export interface AccountRow {
  id: string;
  bankId: string;
  bankName: string;
  bankShortName: string;
  holderName: string;
  iban: string;
  bic: string | null;
  alias: string | null;
  currency: "EUR";
  status: "ACTIVE" | "INACTIVE" | "CLOSED";
}

export const ACME_CHECKING: AccountRow = {
  id: "aaaaaaaa-aaaa-4aaa-8aaa-000000000001",
  bankId: "11111111-1111-4111-8111-111111111111",
  bankName: "Acme Savings",
  bankShortName: "ACME",
  holderName: "Alice Holder",
  iban: "ES9121000418450200051332",
  bic: "CAIXESBBXXX",
  alias: "Payroll",
  currency: "EUR",
  status: "ACTIVE",
};

export const BETA_RESERVE: AccountRow = {
  id: "bbbbbbbb-bbbb-4bbb-8bbb-000000000002",
  bankId: "22222222-2222-4222-8222-222222222222",
  bankName: "Beta Bank",
  bankShortName: "BETA",
  holderName: "Bob Holder",
  iban: "DE89370400440532013000",
  bic: null,
  alias: null,
  currency: "EUR",
  status: "CLOSED",
};

/** The detail/write resource carries `bankId` + ISO timestamps the list drops. */
function toDetail(row: AccountRow): Record<string, unknown> {
  return {
    id: row.id,
    bankId: row.bankId,
    holderName: row.holderName,
    iban: row.iban,
    bic: row.bic,
    alias: row.alias,
    currency: row.currency,
    status: row.status,
    createdAt: "2026-01-01T10:00:00Z",
    updatedAt: "2026-02-01T10:00:00Z",
  };
}

const AUTHORIZE_PATH = /\/backoffice\/bank-accounts\/realtime\/authorize$/;
const LIST_PATH = /\/api\/v1\/backoffice\/bank-accounts(\?.*)?$/;
const IBAN_LOOKUP_PATH = /\/api\/v1\/backoffice\/bank-accounts\/iban-lookup$/;
const ITEM_PATH = /\/api\/v1\/backoffice\/bank-accounts\/[^/?#]+$/;

async function fulfillJson(route: Route, status: number, body: unknown): Promise<void> {
  await route.fulfill({ status, contentType: "application/json", body: JSON.stringify(body) });
}

/** Mutable controller so a test can change the served rows mid-flight (realtime). */
export interface BankAccountsListApi {
  setRows: (rows: AccountRow[]) => void;
  rename: (id: string, holderName: string) => void;
}

export async function mockBankAccountsListApi(
  page: Page,
  initialRows: AccountRow[] = [ACME_CHECKING, BETA_RESERVE],
): Promise<BankAccountsListApi> {
  let rows = [...initialRows];

  // Authorize is faked to succeed so the (faked) EventSource opens; no real
  // subscriber cookie is needed because the transport itself is stubbed.
  await page.route(AUTHORIZE_PATH, (route) => route.fulfill({ status: 204 }));

  await page.route(
    (url) => LIST_PATH.test(url.pathname) && !ITEM_PATH.test(url.pathname),
    async (route) => {
      if (route.request().method() !== "GET") {
        await route.fallback();
        return;
      }
      await fulfillJson(route, 200, {
        data: rows,
        pagination: {
          hasNext: false,
          hasPrev: false,
          count: null,
          links: { next: null, prev: null },
        },
      });
    },
  );

  await page.route(ITEM_PATH, async (route) => {
    if (route.request().method() !== "GET") {
      await route.fallback();
      return;
    }
    const itemId = new URL(route.request().url()).pathname.split("/").pop() ?? "";
    const row = rows.find((candidate) => candidate.id === itemId) ?? initialRows[0];
    await fulfillJson(route, 200, { data: toDetail({ ...row, id: itemId }) });
  });

  // Registered AFTER `ITEM_PATH` — Playwright tries routes in reverse
  // registration order, and `/bank-accounts/iban-lookup` also matches
  // `ITEM_PATH`'s generic `{id}` shape, so this one must win.
  await page.route(IBAN_LOOKUP_PATH, async (route) => {
    if (route.request().method() !== "POST") {
      await route.fallback();
      return;
    }
    const { iban } = JSON.parse(route.request().postData() ?? "{}") as { iban?: string };
    const row = rows.find((candidate) => candidate.iban === iban);
    if (!row) {
      await fulfillJson(route, 404, {
        type: "bank-account-not-found",
        title: "Bank account with the given IBAN not found.",
        status: 404,
        instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
        "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5d",
      });
      return;
    }
    await fulfillJson(route, 200, { data: row });
  });

  return {
    setRows: (next) => {
      rows = [...next];
    },
    rename: (id, holderName) => {
      rows = rows.map((row) => (row.id === id ? { ...row, holderName } : row));
    },
  };
}

/**
 * Replaces `window.EventSource` with a controllable stand-in BEFORE app code
 * runs, so the realtime subscription opens against a stub the test drives.
 */
export async function installFakeMercure(page: Page): Promise<void> {
  await page.addInitScript(() => {
    const instances: Array<{ onmessage: ((event: { data: string }) => void) | null }> = [];
    class FakeEventSource {
      onmessage: ((event: { data: string }) => void) | null = null;
      onerror: (() => void) | null = null;
      onopen: (() => void) | null = null;
      closed = false;
      constructor() {
        instances.push(this);
      }
      close(): void {
        this.closed = true;
      }
    }
    const globalWithMercure = window as unknown as {
      EventSource: unknown;
      __mercureInstances: typeof instances;
      __emitMercure: (payload: unknown) => void;
    };
    globalWithMercure.EventSource = FakeEventSource;
    globalWithMercure.__mercureInstances = instances;
    globalWithMercure.__emitMercure = (payload: unknown): void => {
      const data = JSON.stringify(payload);
      for (const source of instances) {
        if (source.onmessage && !(source as unknown as { closed: boolean }).closed) {
          source.onmessage({ data });
        }
      }
    };
  });
}

/** Pushes one Mercure message to every open faked subscription. */
export async function emitMercure(page: Page, payload: unknown): Promise<void> {
  await page.evaluate((data) => {
    (window as unknown as { __emitMercure: (p: unknown) => void }).__emitMercure(data);
  }, payload);
}
