import type { Page } from "@playwright/test";
import { clickUntilVisible } from "./click-until-visible";

export async function navigateToHealthViaSidebarDesktop(page: Page): Promise<void> {
  await clickUntilVisible(
    page.getByRole("button", { name: "Configuration" }),
    page.getByRole("button", { name: "System Health" }),
  );
  await page.getByRole("button", { name: "System Health" }).click();
}

export async function navigateToHealthViaSidebarMobile(page: Page): Promise<void> {
  await clickUntilVisible(
    page.getByRole("button", { name: "Open navigation menu" }),
    page.getByRole("dialog"),
  );
  await page.getByRole("button", { name: "System Health" }).click();
}

export async function logoutViaSidebarDesktop(page: Page): Promise<void> {
  await clickUntilVisible(
    page.getByRole("button", { name: "User Profile" }),
    page.getByRole("button", { name: "Logout" }),
  );
  await page.getByRole("button", { name: "Logout" }).click();
}

export async function logoutViaSidebarMobile(page: Page): Promise<void> {
  await clickUntilVisible(
    page.getByRole("button", { name: "Open navigation menu" }),
    page.getByRole("dialog"),
  );
  await page.getByRole("button", { name: "Logout" }).click();
}
