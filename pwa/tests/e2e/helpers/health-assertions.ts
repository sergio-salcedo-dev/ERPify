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
  const banner = page.getByTestId("backoffice-health__banner");
  await expect(banner).toBeVisible({ timeout: HEALTH_CHECK_TIMEOUT_MS });
  await expect(banner).toContainText(/All Systems Operational/i);

  const component = page.getByTestId("backoffice-health__component-backoffice");
  await expect(component).toContainText(/BackOffice API/i);
  await expect(component).toContainText(/Operational/i);
}
