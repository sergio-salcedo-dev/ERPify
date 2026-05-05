import type { Locator } from "@playwright/test";
import { expect } from "@playwright/test";

/**
 * Click `trigger` and wait for `awaited` to become visible. If the click is dropped (e.g. a render
 * cycle re-mounted the React handler between Playwright resolving the trigger and dispatching the
 * click), retry the click until the awaited element actually shows up.
 */
export async function clickUntilVisible(trigger: Locator, awaited: Locator): Promise<void> {
  await expect(trigger).toBeVisible();
  await expect(async () => {
    if (!(await awaited.isVisible())) {
      await trigger.click();
    }
    await expect(awaited).toBeVisible({ timeout: 1_000 });
  }).toPass({ timeout: 10_000 });
}
