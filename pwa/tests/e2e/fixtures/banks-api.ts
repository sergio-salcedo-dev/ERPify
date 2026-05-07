import type { Page, Route } from "@playwright/test";

export interface BankFixture {
  id: string;
  name: string;
  shortName: string;
  createdAt: string;
  updatedAt: string;
}

export const SAMPLE_BANK_A: BankFixture = {
  id: "11111111-1111-4111-8111-111111111111",
  name: "Acme Savings",
  shortName: "ACME",
  createdAt: "2026-01-01T10:00:00Z",
  updatedAt: "2026-04-15T14:30:00Z",
};

export const SAMPLE_BANK_B: BankFixture = {
  id: "22222222-2222-4222-8222-222222222222",
  name: "Brookline Trust",
  shortName: "BRT",
  createdAt: "2026-02-12T09:00:00Z",
  updatedAt: "2026-04-20T16:00:00Z",
};

export type ListScenario = "happy" | "empty" | "server-error";
export type GetScenario = "happy" | "not-found" | "server-error";
export type CreateScenario = "happy" | "validation-error";
export type UpdateScenario = "happy" | "validation-error";
export type DeleteScenario = "happy" | "not-found";

export interface BanksApiScenario {
  list?: ListScenario;
  get?: GetScenario;
  create?: CreateScenario;
  update?: UpdateScenario;
  delete?: DeleteScenario;
  /** Bank fixture used to satisfy GET/{id} and updates. Defaults to SAMPLE_BANK_A. */
  bank?: BankFixture;
  /** Banks returned by the list endpoint when scenario.list === "happy". */
  list_banks?: BankFixture[];
}

const LIST_PATH = /\/api\/v1\/backoffice\/banks(\?.*)?$/;
const ITEM_PATH = /\/api\/v1\/backoffice\/banks\/[^/?#]+$/;

function legacyError(parameter: string, title: string, requestId: string): unknown {
  return {
    errors: [{ source: { parameter }, title }],
    meta: { requestId },
  };
}

async function fulfillJson(route: Route, status: number, body: unknown): Promise<void> {
  await route.fulfill({
    status,
    contentType: "application/json",
    body: JSON.stringify(body),
  });
}

export async function mockBanksApi(page: Page, scenario: BanksApiScenario): Promise<void> {
  const bank = scenario.bank ?? SAMPLE_BANK_A;
  const listBanks = scenario.list_banks ?? [SAMPLE_BANK_A, SAMPLE_BANK_B];

  await page.route(
    (url) => LIST_PATH.test(url.pathname) && !ITEM_PATH.test(url.pathname),
    async (route) => {
      const method = route.request().method();

      if (method === "GET") {
        switch (scenario.list) {
          case "empty":
            await fulfillJson(route, 200, { data: [] });
            return;
          case "server-error":
            await fulfillJson(
              route,
              500,
              legacyError("server", "Database unavailable.", "01H-list-error"),
            );
            return;
          case "happy":
          default:
            await fulfillJson(route, 200, { data: listBanks });
            return;
        }
      }

      if (method === "POST") {
        switch (scenario.create) {
          case "validation-error":
            await fulfillJson(
              route,
              422,
              legacyError("name", "The name field is required.", "01H-create-422"),
            );
            return;
          case "happy":
          default:
            await fulfillJson(route, 201, { data: bank });
            return;
        }
      }

      await route.fallback();
    },
  );

  await page.route(
    (url) => ITEM_PATH.test(url.pathname),
    async (route) => {
      const method = route.request().method();

      if (method === "GET") {
        switch (scenario.get) {
          case "not-found":
            await fulfillJson(route, 404, legacyError("uuid", "Bank not found.", "01H-get-404"));
            return;
          case "server-error":
            await fulfillJson(
              route,
              500,
              legacyError("server", "Database unavailable.", "01H-get-error"),
            );
            return;
          case "happy":
          default:
            await fulfillJson(route, 200, { data: bank });
            return;
        }
      }

      if (method === "PUT") {
        switch (scenario.update) {
          case "validation-error":
            await fulfillJson(
              route,
              422,
              legacyError(
                "shortName",
                "The shortName must not exceed 50 characters.",
                "01H-update-422",
              ),
            );
            return;
          case "happy":
          default:
            await fulfillJson(route, 200, { data: bank });
            return;
        }
      }

      if (method === "DELETE") {
        switch (scenario.delete) {
          case "not-found":
            await fulfillJson(route, 404, legacyError("uuid", "Bank not found.", "01H-delete-404"));
            return;
          case "happy":
          default:
            await route.fulfill({ status: 204 });
            return;
        }
      }

      await route.fallback();
    },
  );
}
