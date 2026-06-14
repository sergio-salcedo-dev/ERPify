import { headers } from "next/headers";

/**
 * Request header the Playwright suite sets on every request
 * (`playwright.config.ts` → `use.extraHTTPHeaders`). Keep the literal in sync there.
 */
export const E2E_REQUEST_HEADER = "x-erpify-e2e";

/**
 * Whether the current request is driven by the automated e2e suite. The dev
 * debug toolbar is a human aid: under Playwright it injects Symfony profiler DOM
 * (the AJAX panel grows a `<tbody><tr>` per `/api/*` call) that pollutes the
 * document-wide locators existing specs rely on, so the toolbar is not mounted
 * for automated runs. A real user / manual dev session never sends this header.
 */
export async function isAutomatedTestRequest(): Promise<boolean> {
  const requestHeaders = await headers();
  return requestHeaders.get(E2E_REQUEST_HEADER) === "1";
}
