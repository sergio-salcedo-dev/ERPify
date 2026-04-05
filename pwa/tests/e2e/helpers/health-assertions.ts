import { expect, type Page } from "@playwright/test";
import { HEALTH_CHECK_TIMEOUT_MS } from "../constants";

export async function expectFrontOfficeHealthOk(page: Page): Promise<void> {
  const status = page.getByTestId("frontoffice-health-status");
  await expect(status).toBeVisible({ timeout: HEALTH_CHECK_TIMEOUT_MS });
  await expect(status).toContainText(/Status:\s*ok/i);
  await expect(status).toContainText(/Service:\s*Front office/i);
  await expect(status).toContainText(/Date:/i);
}

export async function expectBackOfficeHealthOk(page: Page): Promise<void> {
  const status = page.getByTestId("backoffice-health-status");
  await expect(status).toBeVisible({ timeout: HEALTH_CHECK_TIMEOUT_MS });
  await expect(status).toContainText(/Status:\s*ok/i);
  await expect(status).toContainText(/Service:\s*Back office/i);
  await expect(status).toContainText(/Date:/i);
}
