import { notFound } from "next/navigation";
import { NodeEnv } from "@/context/shared/domain/types/nodeEnv";

/**
 * Deterministic error message thrown by this debug route. Exported so the
 * Playwright spec at `tests/e2e/error-pages.spec.ts` can assert the dev-mode
 * `error.tsx` boundary actually rendered the original throw.
 */
export const THROW_FIXTURE_MESSAGE =
  "Triggered by /__throw — debug fixture for the error.tsx boundary.";

/**
 * Debug-only fixture route used to exercise `app/error.tsx` end-to-end.
 *
 * - In `development` and `test` (which is what `make dev` and `make pwa.test.e2e`
 *   spin up) the page throws synchronously on the server; Next.js catches the
 *   throw, renders the `error.tsx` boundary, and sends the response with HTTP
 *   500 — exactly the path a real uncaught render error walks.
 * - In `production` builds the route hides itself behind `notFound()` so the
 *   debug surface never ships to real users. A direct visit returns the
 *   regular `not-found.tsx` page (HTTP 404) instead.
 *
 * Triggered manually (`make dev` then `https://localhost/__throw`) or
 * automatically by the E2E spec.
 */
export default function ThrowPage(): never {
  if (process.env.NODE_ENV === NodeEnv.PRODUCTION) {
    notFound();
  }
  throw new Error(THROW_FIXTURE_MESSAGE);
}
