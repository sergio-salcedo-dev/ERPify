import type { Page, Route } from "@playwright/test";

/**
 * Mocked-route fixtures for the standalone bank-account write API plus the nested
 * per-bank accounts list. The list resource (`GET /banks/{id}/accounts`) omits
 * `bankId`/timestamps (they are the route / audit, not payload); the detail and
 * write resources (`GET|POST|PUT /bank-accounts`) carry the full record.
 */

export const SAMPLE_BANK_ID = "11111111-1111-7111-8111-111111111111";

export interface AccountFixture {
  id: string;
  bankId: string;
  holderName: string;
  iban: string;
  bic: string | null;
  alias: string | null;
  currency: "EUR";
  status: "ACTIVE" | "INACTIVE" | "CLOSED";
  createdAt: string;
  updatedAt: string;
}

export const CLOSED_ACCOUNT: AccountFixture = {
  id: "0190ffff-aaaa-7bbb-8ccc-000000000001",
  bankId: SAMPLE_BANK_ID,
  holderName: "Closed Holder",
  iban: "ES9121000418450200051332",
  bic: null,
  alias: "Old payroll",
  currency: "EUR",
  status: "CLOSED",
  createdAt: "2026-01-01T10:00:00Z",
  updatedAt: "2026-02-01T10:00:00Z",
};

export const ACTIVE_ACCOUNT: AccountFixture = {
  id: "0190ffff-aaaa-7bbb-8ccc-000000000002",
  bankId: SAMPLE_BANK_ID,
  holderName: "Active Holder",
  iban: "DE89370400440532013000",
  bic: null,
  alias: null,
  currency: "EUR",
  status: "ACTIVE",
  createdAt: "2026-01-02T10:00:00Z",
  updatedAt: "2026-02-02T10:00:00Z",
};

/** The list projection drops bankId + timestamps. */
function toListItem(account: AccountFixture): Record<string, unknown> {
  return {
    id: account.id,
    holderName: account.holderName,
    iban: account.iban,
    bic: account.bic,
    alias: account.alias,
    currency: account.currency,
    status: account.status,
  };
}

export type ListScenario = "happy" | "empty" | "server-error";
export type GetScenario = "happy" | "not-found";
export type CreateScenario = "happy" | "validation-error" | "server-error";
export type UpdateScenario = "happy" | "validation-error" | "not-found";
export type DeleteScenario = "happy" | "not-found" | "not-closed";

export interface BankAccountsApiScenario {
  list?: ListScenario;
  get?: GetScenario;
  create?: CreateScenario;
  update?: UpdateScenario;
  delete?: DeleteScenario;
  /** Account satisfying GET/{id}, the create/update responses, and the delete target. */
  account?: AccountFixture;
  /** Accounts returned by the list endpoint when `list === "happy"`. */
  list_accounts?: AccountFixture[];
}

const NESTED_LIST_PATH = /\/api\/v1\/backoffice\/banks\/[^/?#]+\/accounts(\?.*)?$/;
const CREATE_PATH = /\/api\/v1\/backoffice\/bank-accounts$/;
const ITEM_PATH = /\/api\/v1\/backoffice\/bank-accounts\/[^/?#]+$/;

function problemBody(
  type: string,
  title: string,
  status: number,
  correlationId: string,
  overrides: { detail?: string; violations?: { field: string; message: string }[] } = {},
): Record<string, unknown> {
  return {
    type,
    title,
    status,
    instance: `${correlationId}-instance`,
    "correlation-id": correlationId,
    ...(overrides.detail === undefined ? {} : { detail: overrides.detail }),
    ...(overrides.violations ? { violations: overrides.violations } : {}),
  };
}

async function fulfillJson(route: Route, status: number, body: unknown): Promise<void> {
  await route.fulfill({ status, contentType: "application/json", body: JSON.stringify(body) });
}

async function fulfillProblem(
  route: Route,
  status: number,
  body: Record<string, unknown>,
  correlationId: string,
): Promise<void> {
  await route.fulfill({
    status,
    contentType: "application/problem+json",
    headers: { "X-Correlation-Id": correlationId, "Cache-Control": "no-store" },
    body: JSON.stringify(body),
  });
}

export async function mockBankAccountsApi(
  page: Page,
  scenario: BankAccountsApiScenario,
): Promise<void> {
  const account = scenario.account ?? CLOSED_ACCOUNT;
  const listAccounts = scenario.list_accounts ?? [CLOSED_ACCOUNT, ACTIVE_ACCOUNT];
  // A successful DELETE records the id so the next (silent-reload) list omits it,
  // emulating the real backend dropping the row.
  const deletedIds = new Set<string>();

  // Nested per-bank list (the hub).
  await page.route(NESTED_LIST_PATH, async (route) => {
    if (route.request().method() !== "GET") {
      await route.fallback();
      return;
    }
    if (scenario.list === "server-error") {
      const correlationId = "01H-acct-list-error";
      await fulfillProblem(
        route,
        500,
        problemBody("unhandled-exception", "Database unavailable.", 500, correlationId),
        correlationId,
      );
      return;
    }
    const items =
      scenario.list === "empty"
        ? []
        : listAccounts.filter((candidate) => !deletedIds.has(candidate.id));
    await fulfillJson(route, 200, {
      data: items.map(toListItem),
      pagination: {
        hasNext: false,
        hasPrev: false,
        count: null,
        links: { next: null, prev: null },
      },
    });
  });

  // Create (POST /bank-accounts).
  await page.route(CREATE_PATH, async (route) => {
    if (route.request().method() !== "POST") {
      await route.fallback();
      return;
    }
    switch (scenario.create) {
      case "validation-error": {
        const correlationId = "01H-acct-create-422";
        await fulfillProblem(
          route,
          422,
          problemBody("validation-failed", "Validation failed.", 422, correlationId, {
            violations: [{ field: "iban", message: "This IBAN is already registered." }],
          }),
          correlationId,
        );
        return;
      }
      case "server-error": {
        const correlationId = "01H-acct-create-500";
        await fulfillProblem(
          route,
          500,
          problemBody("unhandled-exception", "Something went wrong.", 500, correlationId, {
            detail: "The account could not be created.",
          }),
          correlationId,
        );
        return;
      }
      case "happy":
      default:
        await fulfillJson(route, 201, { data: account });
        return;
    }
  });

  // Detail / update / delete (/bank-accounts/{id}).
  await page.route(ITEM_PATH, async (route) => {
    const method = route.request().method();
    const itemId = new URL(route.request().url()).pathname.split("/").pop() ?? "";

    if (method === "GET") {
      if (scenario.get === "not-found") {
        const correlationId = "01H-acct-get-404";
        await fulfillProblem(
          route,
          404,
          problemBody("bank-account-not-found", "Account not found.", 404, correlationId),
          correlationId,
        );
        return;
      }
      await fulfillJson(route, 200, { data: { ...account, id: itemId } });
      return;
    }

    if (method === "PUT") {
      switch (scenario.update) {
        case "validation-error": {
          const correlationId = "01H-acct-update-422";
          await fulfillProblem(
            route,
            422,
            problemBody("validation-failed", "Validation failed.", 422, correlationId, {
              violations: [{ field: "holderName", message: "The holder name field is required." }],
            }),
            correlationId,
          );
          return;
        }
        case "not-found": {
          const correlationId = "01H-acct-update-404";
          await fulfillProblem(
            route,
            404,
            problemBody("bank-account-not-found", "Account not found.", 404, correlationId, {
              detail: "It may have been deleted.",
            }),
            correlationId,
          );
          return;
        }
        case "happy":
        default:
          await fulfillJson(route, 200, { data: { ...account, id: itemId } });
          return;
      }
    }

    if (method === "DELETE") {
      switch (scenario.delete) {
        case "not-found": {
          const correlationId = "01H-acct-delete-404";
          await fulfillProblem(
            route,
            404,
            problemBody("bank-account-not-found", "Account not found.", 404, correlationId),
            correlationId,
          );
          return;
        }
        case "not-closed": {
          const correlationId = "01H-acct-delete-409";
          await fulfillProblem(
            route,
            409,
            problemBody(
              "bank-account-not-closed",
              "Account cannot be deleted: it is not closed",
              409,
              correlationId,
              { detail: "Set the account status to Closed before deleting it." },
            ),
            correlationId,
          );
          return;
        }
        case "happy":
        default:
          deletedIds.add(itemId);
          await route.fulfill({ status: 204 });
          return;
      }
    }

    await route.fallback();
  });
}
