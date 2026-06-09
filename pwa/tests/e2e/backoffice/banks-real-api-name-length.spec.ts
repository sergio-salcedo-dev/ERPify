import { test, expect, type APIRequestContext } from "@playwright/test";
import {
  createApiContext,
  deleteBanksSafely,
  uniqueRunPrefix,
  type ApiBank,
} from "../fixtures/banks-real-api";

/**
 * Real-API E2E coverage for the Bank `name` max-length rule (255 chars),
 * driven directly against the live Symfony API rather than through `page.route`
 * mocks — the mocked suites (`banks.spec.ts`) stub the 422 response, so they
 * cannot catch a server-side validation regression. This suite asserts the
 * actual backend behaviour the user relies on.
 *
 * It covers three boundaries:
 * - a plain name of 256 chars is rejected with a field-level `name` violation;
 * - a name whose accent-folded form overflows the unique `nameNormalized`
 *   column is rejected as a clean validation error, NOT a database 500. "Æ"
 *   transliterates to "ae" for that column, so a 200-char name (≤ 255 code
 *   points) folds to 400 chars and used to throw `SQLSTATE[22001] value too
 *   long`. This is the regression guard for that fix;
 * - a name of exactly 255 chars is accepted (the limit is inclusive).
 *
 * Running locally requires the full stack (`make docker.up`) — the fixtures
 * resolve the API origin from `PLAYWRIGHT_API_BASE_URL` / `PLAYWRIGHT_BASE_URL`,
 * defaulting to `https://localhost`.
 */
const BANKS_PATH = "/api/v1/backoffice/banks";
const MAX_NAME_LENGTH = 255;
const NAME_TOO_LONG_MESSAGE = "The name must not exceed 255 characters.";

interface ProblemViolation {
  field: string;
  message: string;
}

interface ProblemResponse {
  violations?: ProblemViolation[];
}

test.describe("BackOffice - Bank name length validation (real API)", () => {
  const runPrefix = uniqueRunPrefix("BankNameLen");
  const trackedIds: string[] = [];
  let api: APIRequestContext;

  test.beforeAll(async () => {
    api = await createApiContext();
  });

  test.afterAll(async () => {
    await deleteBanksSafely(api, trackedIds);
    await api.dispose();
  });

  test("rejects a name longer than 255 characters with a field-level violation", async () => {
    const response = await api.post(BANKS_PATH, {
      data: {
        name: "A".repeat(MAX_NAME_LENGTH + 1),
        shortName: `${runPrefix}-OVF`.slice(0, 50),
      },
      headers: { "Content-Type": "application/json" },
    });

    expect(response.status()).toBe(422);
    const body = (await response.json()) as ProblemResponse;
    expect(body.violations).toContainEqual(
      expect.objectContaining({ field: "name", message: NAME_TOO_LONG_MESSAGE }),
    );
  });

  test("rejects a name whose accent-folded form overflows the column with a 422, not a 500", async () => {
    const response = await api.post(BANKS_PATH, {
      data: {
        name: "Æ".repeat(200),
        shortName: `${runPrefix}-MB`.slice(0, 50),
      },
      headers: { "Content-Type": "application/json" },
    });

    expect(response.status()).toBe(422);
    const body = (await response.json()) as ProblemResponse;
    expect(body.violations).toContainEqual(
      expect.objectContaining({ field: "name", message: NAME_TOO_LONG_MESSAGE }),
    );
  });

  test("accepts a name of exactly 255 characters", async () => {
    const filler = "A".repeat(MAX_NAME_LENGTH - runPrefix.length - 1);
    const name = `${runPrefix} ${filler}`;
    expect(name).toHaveLength(MAX_NAME_LENGTH);

    const response = await api.post(BANKS_PATH, {
      data: { name, shortName: `${runPrefix}-255`.slice(0, 50) },
      headers: { "Content-Type": "application/json" },
    });

    expect(response.status()).toBe(201);
    const body = (await response.json()) as { data: ApiBank };
    trackedIds.push(body.data.id);
    expect(body.data.name).toBe(name);
  });
});
