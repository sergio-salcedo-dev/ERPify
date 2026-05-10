import { test, expect, type Page } from "@playwright/test";
import { VIEWPORT_DESKTOP, VIEWPORT_MOBILE } from "./constants";

/**
 * E2E coverage for the PWA's branded error pages.
 *
 * Scope
 * - Static / navigable error routes (`/maintenance`, `/rate-limited`,
 *   `/offline`, `/unauthenticated`, `/unauthorized`).
 * - The Next.js `not-found.tsx` boundary (any unmatched URL).
 * - The Next.js `error.tsx` boundary (driven by the dev-only `/dev-throw`
 *   fixture in `app/(errors)/dev-throw/page.tsx`).
 * - The `<ErrorActions>` row's pathname-aware primary CTA (`Return home`
 *   vs `Return to BackOffice`).
 *
 * Out of scope
 * - Production redaction of `error.message` is a build-time guard
 *   (`process.env.NODE_ENV === NodeEnv.DEVELOPMENT`); CI E2E runs against
 *   the dev stack so we assert dev-mode visibility. The matching production
 *   verification is documented in `pwa/docs/error-pages-testing.md`.
 */

/** Mirrors the constant exported from `app/(errors)/dev-throw/page.tsx`. */
const THROW_FIXTURE_MESSAGE = "Triggered by /dev-throw — debug fixture for the error.tsx boundary.";

interface StaticErrorPageCase {
  /** Display name for the test description. */
  name: string;
  /** URL to navigate to. */
  url: string;
  /** `data-testid` prefix used by `<ErrorScreen>` for this page. */
  testId: string;
  /** Status line (e.g. `Error 404`). */
  status: string;
  /** Title heading. */
  title: string;
  /** Substring that must appear in the description. */
  descriptionContains: string;
  /** HTTP status the route is expected to respond with. */
  expectedHttpStatus: number;
}

const STATIC_PAGES: ReadonlyArray<StaticErrorPageCase> = [
  {
    name: "404 — not found",
    url: "/this-route-definitely-does-not-exist",
    testId: "not-found",
    status: "Error 404",
    title: "Page not found",
    descriptionContains: "doesn't exist or has been moved",
    expectedHttpStatus: 404,
  },
  {
    name: "401 — unauthenticated",
    url: "/unauthenticated",
    testId: "unauthenticated",
    status: "Error 401",
    title: "Sign in required",
    descriptionContains: "sign in again",
    expectedHttpStatus: 200,
  },
  {
    name: "403 — unauthorized",
    url: "/unauthorized",
    testId: "unauthorized",
    status: "Error 403",
    title: "Access denied",
    descriptionContains: "do not have permission",
    expectedHttpStatus: 200,
  },
  {
    name: "503 — maintenance",
    url: "/maintenance",
    testId: "maintenance",
    status: "Error 503",
    title: "Scheduled maintenance",
    descriptionContains: "temporarily offline",
    expectedHttpStatus: 200,
  },
  {
    name: "429 — rate limited",
    url: "/rate-limited",
    testId: "rate-limited",
    status: "Error 429",
    title: "Too many requests",
    descriptionContains: "request limit",
    expectedHttpStatus: 200,
  },
  {
    name: "offline — PWA fallback",
    url: "/offline",
    testId: "offline",
    status: "No connection",
    title: "You're offline",
    descriptionContains: "could not reach the network",
    expectedHttpStatus: 200,
  },
];

async function expectErrorScreenSkeleton(
  page: Page,
  testId: string,
  status: string,
  title: string,
  descriptionContains: string,
): Promise<void> {
  // The shared `<ErrorScreen>` shell wires every error page identically; if
  // any of these go missing we have a regression in the module, not in the
  // individual route.
  await expect(page.getByTestId(testId)).toBeVisible();
  await expect(page.getByTestId(`${testId}__panel`)).toBeVisible();
  await expect(page.getByTestId(`${testId}__status`)).toHaveText(status);
  await expect(page.getByTestId(`${testId}__title`)).toHaveText(title);
  if (descriptionContains) {
    await expect(page.getByTestId(`${testId}__description`)).toContainText(descriptionContains, {
      ignoreCase: true,
    });
  }
}

async function expectStandardActions(page: Page, primaryName: RegExp): Promise<void> {
  // The shared `<ErrorActions>` row always renders a primary navigation
  // link plus a `Go back` button. The primary's accessible name flips
  // between `Home` and `BackOffice` depending on the URL prefix.
  await expect(page.getByRole("link", { name: primaryName })).toBeVisible();
  await expect(page.getByTestId("error-actions__back-button")).toBeVisible();
}

test.describe("Error pages — static / navigable routes", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  for (const tc of STATIC_PAGES) {
    test(tc.name, async ({ page }) => {
      const response = await page.goto(tc.url);
      expect(response, "navigation should produce a response").not.toBeNull();
      // 404 is served as 404; the bespoke routes return 200 because they're
      // navigable info pages — middleware / API redirects decide when to
      // route real users to them.
      expect(response?.status()).toBe(tc.expectedHttpStatus);

      await expectErrorScreenSkeleton(page, tc.testId, tc.status, tc.title, tc.descriptionContains);
      await expectStandardActions(page, /^Home$/);
    });
  }

  test("Go back navigates the user away from the error route", async ({ page }) => {
    // The button must always do *something*: when there's history it walks
    // back, when there isn't it pushes the primary destination. The only
    // outcome we forbid is the user being stuck on the same error URL.
    await page.goto("/this-route-definitely-does-not-exist");
    await page.getByTestId("error-actions__back-button").click();
    await expect(page).not.toHaveURL(/this-route-definitely-does-not-exist/);
  });
});

test.describe("Error pages — pathname-aware primary CTA", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  test("inside /backoffice the primary CTA returns to the BackOffice", async ({ page }) => {
    const response = await page.goto("/backoffice/this-segment-does-not-exist");
    expect(response?.status()).toBe(404);
    await expect(page.getByTestId("not-found")).toBeVisible();
    await expect(page.getByTestId("error-actions__backoffice-link")).toBeVisible();
    await expect(page.getByTestId("error-actions__home-link")).not.toBeVisible();
  });

  test("outside /backoffice the primary CTA returns home", async ({ page }) => {
    const response = await page.goto("/this-route-definitely-does-not-exist");
    expect(response?.status()).toBe(404);
    await expect(page.getByTestId("not-found")).toBeVisible();
    await expect(page.getByTestId("error-actions__home-link")).toBeVisible();
    await expect(page.getByTestId("error-actions__backoffice-link")).not.toBeVisible();
  });
});

test.describe("Error pages — 500 boundary (error.tsx)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  test("renders the error.tsx boundary when a server component throws", async ({ page }) => {
    // The `dev-throw` fixture is dev/test-only — it calls `notFound()` in
    // production builds, see `app/(errors)/dev-throw/page.tsx`.
    const response = await page.goto("/dev-throw");
    expect(response, "navigation should produce a response").not.toBeNull();
    expect(response?.status()).toBe(500);

    await expectErrorScreenSkeleton(
      page,
      "error-page",
      "Error 500",
      "Something went wrong",
      "unexpected problem",
    );

    // Try again (reset()) — the canonical Next boundary recovery path.
    await expect(page.getByTestId("error-page__retry")).toBeVisible();

    // Standard navigation row.
    await expect(page.getByTestId("error-actions__home-link")).toBeVisible();
    await expect(page.getByTestId("error-actions__back-button")).toBeVisible();
  });

  test("dev mode reveals the original error.message inside error-page__details", async ({
    page,
  }) => {
    // CI runs E2E against the dev stack (`make pwa.test.e2e`), so
    // `process.env.NODE_ENV === "development"` and the dev-only details
    // block must contain the message we threw. The matching production
    // verification is manual and documented in
    // `pwa/docs/error-pages-testing.md`.
    await page.goto("/dev-throw");
    const details = page.getByTestId("error-page__details");
    await expect(details).toBeVisible();
    await expect(details).toContainText(THROW_FIXTURE_MESSAGE);
  });

  test("the Error ID can be copied to the clipboard", async ({ page, context }) => {
    await context.grantPermissions(["clipboard-read", "clipboard-write"]);
    await page.goto("/dev-throw");

    // Next assigns a digest hash to every server-thrown error; the digest
    // block plus its copy button must therefore always render.
    const digestValue = page.getByTestId("error-page__digest-value");
    await expect(digestValue).toBeVisible();
    const expectedDigest = await digestValue.innerText();
    expect(expectedDigest, "Next should provide a non-empty digest").not.toEqual("");

    const copyButton = page.getByTestId("error-page__digest-copy");
    await expect(copyButton).toBeVisible();
    await copyButton.click();

    // The CopyButton flips its `data-copy-status` to `copied` while the
    // success cue is on screen — that's also what turns the check icon
    // green via the Tailwind `data-[copy-status=copied]:[&_svg]:text-success`
    // override.
    await expect(copyButton).toHaveAttribute("data-copy-status", "copied");

    const clipboardValue = await page.evaluate(() => navigator.clipboard.readText());
    expect(clipboardValue).toBe(expectedDigest);
  });
});

test.describe("Error pages — responsive layout", () => {
  test("mobile viewport keeps the panel visible end-to-end", async ({ page }) => {
    await page.setViewportSize(VIEWPORT_MOBILE);
    await page.goto("/maintenance");
    await expect(page.getByTestId("maintenance__panel")).toBeVisible();
    // On mobile both buttons stack full-width — they must still both be
    // reachable / visible without horizontal scrolling.
    await expect(page.getByTestId("error-actions__home-link")).toBeVisible();
    await expect(page.getByTestId("error-actions__back-button")).toBeVisible();
  });

  test("desktop viewport renders the panel above the fold", async ({ page }) => {
    await page.setViewportSize(VIEWPORT_DESKTOP);
    await page.goto("/unauthorized");
    const panel = page.getByTestId("unauthorized__panel");
    await expect(panel).toBeVisible();
  });
});
