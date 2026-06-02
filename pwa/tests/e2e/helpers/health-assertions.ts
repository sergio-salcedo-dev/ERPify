import { expect, type Page } from "@playwright/test";
import { HEALTH_CHECK_TIMEOUT_MS } from "../constants";

export async function expectStatusPageOperational(page: Page): Promise<void> {
  const banner = page.getByTestId("status-page__banner");
  await expect(banner).toBeVisible({ timeout: HEALTH_CHECK_TIMEOUT_MS });
  await expect(banner).toContainText(/All Systems Operational/i);

  const component = page.getByTestId("status-page__component-frontoffice");
  await expect(component).toContainText(/FrontOffice API/i);
  await expect(component).toContainText(/Operational/i);
}

export async function expectBackOfficeHealthOk(page: Page): Promise<void> {
  const status = page.getByTestId("backoffice-health-status");
  await expect(status).toBeVisible({ timeout: HEALTH_CHECK_TIMEOUT_MS });
  await expect(status).toContainText(/Status:\s*ok/i);
  await expect(status).toContainText(/Service:\s*Back office/i);
  await expect(status).toContainText(/Date:/i);
}
