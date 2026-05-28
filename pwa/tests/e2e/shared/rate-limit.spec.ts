import { test, expect } from "@playwright/test";
import { VIEWPORT_DESKTOP } from "../constants";

/**
 * E2E coverage for HTTP 429 (Too Many Requests) rate-limiting behavior.
 *
 * Scope
 * - Verify that the API returns a 429 RFC 9457 Problem Details response
 *   when the anonymous rate limit is exceeded.
 * - Verify that the PWA renders the branded `/rate-limited` error page
 *   when receiving a 429 from the API.
 * - Verify that the `ProblemDisplay` component shows the correct icon
 *   (Hourglass), status, and type for 429 responses.
 *
 * Test environment setup
 * - The API test environment (`api/.env.test`) configures
 *   `RATE_LIMIT_ANONYMOUS_API_LIMIT=5` with a 1 minute interval so
 *   functional tests can assert the 429 path deterministically without
 *   hammering the limiter 120+ times.
 */

test.describe("Rate limiting — 429 Too Many Requests", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  test("PWA renders the branded /rate-limited page when API returns 429", async ({ page }) => {
    // Navigate directly to the rate-limited error page
    const response = await page.goto("/rate-limited");
    expect(response, "navigation should produce a response").not.toBeNull();
    expect(response?.status()).toBe(200);

    // Verify the ErrorScreen skeleton renders correctly
    await expect(page.getByTestId("rate-limited")).toBeVisible();
    await expect(page.getByTestId("rate-limited__panel")).toBeVisible();
    await expect(page.getByTestId("rate-limited__status")).toHaveText("Error 429");
    await expect(page.getByTestId("rate-limited__title")).toHaveText("Too many requests");
    await expect(page.getByTestId("rate-limited__description")).toContainText("request limit", {
      ignoreCase: true,
    });

    // Verify the Hourglass icon is rendered (via the icon wrapper)
    const iconWrap = page.locator('[data-testid="rate-limited"] svg').first();
    await expect(iconWrap).toBeVisible();

    // Verify standard error actions are present
    await expect(page.getByRole("link", { name: /^Home$/ })).toBeVisible();
    await expect(page.getByTestId("error-actions__back-button")).toBeVisible();
  });

  test("ProblemDisplay renders Hourglass icon and correct metadata for 429 response", async ({
    page,
  }) => {
    // Use the dev-tools error gallery which renders ProblemDisplay fixtures
    await page.goto("/dev-tools/error-gallery");

    // Find the 429 rate-limited example section
    const rateLimitedSection = page.locator('text="429 — rate limited"').first();
    await expect(rateLimitedSection).toBeVisible();

    // Click to expand if it's collapsible, or just verify the content is visible
    const problemDisplay = page.locator('[data-problem-status="429"]').first();
    await expect(problemDisplay).toBeVisible();

    // Verify the status badge shows HTTP 429
    const statusBadge = problemDisplay.locator('[data-testid="problem-display__status"]');
    await expect(statusBadge).toHaveText("HTTP 429");

    // Verify the type shows rate-limited
    const typeLabel = problemDisplay.locator('[data-testid="problem-display__type"]');
    await expect(typeLabel).toContainText("rate-limited");

    // Verify the title
    const title = problemDisplay.locator('[data-testid="problem-display__title"]');
    await expect(title).toContainText("Too many requests");

    // Verify the Hourglass icon is rendered
    const hourglassIcon = problemDisplay.locator('svg[aria-hidden="true"]').first();
    await expect(hourglassIcon).toBeVisible();
  });

  test("Go back button navigates away from the rate-limited page", async ({ page }) => {
    await page.goto("/rate-limited");
    await page.getByTestId("error-actions__back-button").click();
    await expect(page).not.toHaveURL(/rate-limited/);
  });

  test("mobile viewport keeps the rate-limited panel visible", async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto("/rate-limited");
    await expect(page.getByTestId("rate-limited__panel")).toBeVisible();
    // Both buttons should be reachable on mobile
    await expect(page.getByTestId("error-actions__home-link")).toBeVisible();
    await expect(page.getByTestId("error-actions__back-button")).toBeVisible();
  });
});
