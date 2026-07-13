/** Resolve the API origin from the same env Playwright resolves the PWA at. */
export function apiBaseURL(): string {
  return (
    process.env.PLAYWRIGHT_API_BASE_URL?.trim() ||
    process.env.PLAYWRIGHT_BASE_URL?.trim() ||
    "https://localhost"
  );
}
