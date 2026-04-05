import type { Page } from "@playwright/test";

export async function navigateToHealthViaSidebarDesktop(page: Page): Promise<void> {
  await page.getByRole("button", { name: "Administration" }).click();
  await page.getByRole("button", { name: "Health" }).click();
}

export async function navigateToHealthViaSidebarMobile(page: Page): Promise<void> {
  await page.getByRole("button", { name: "Open navigation menu" }).click();
  // Mobile nav: "Administration" navigates to its path; Health is a separate control in the sheet.
  await page.getByRole("button", { name: "Health" }).click();
}

export async function logoutViaSidebarDesktop(page: Page): Promise<void> {
  await page.getByRole("button", { name: "User Profile" }).click();
  await page.getByRole("button", { name: "Logout" }).click();
}

export async function logoutViaSidebarMobile(page: Page): Promise<void> {
  await page.getByRole("button", { name: "Open navigation menu" }).click();
  await page.getByRole("button", { name: "Logout" }).click();
}
