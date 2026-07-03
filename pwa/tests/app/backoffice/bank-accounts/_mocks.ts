import { vi } from "vitest";
import type { BankAccountRealtimeHandlers } from "@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime";

/**
 * Shared `vi.mock` factories for the standalone bank-accounts spec suite. Each
 * test still declares its own `vi.mock(...)` call (vitest hoists those per file),
 * but the DI-container / toast / realtime boilerplate lives here so it is not
 * copy-pasted across specs.
 */

/**
 * `next/navigation` mock. `spies.push` asserts navigation; `params` feeds the
 * detail page's `useParams` (the list page never reads it).
 */
export function routerMock(
  spies: { push?: ReturnType<typeof vi.fn>; refresh?: ReturnType<typeof vi.fn> } = {},
  params: Record<string, string> = {},
) {
  return {
    useRouter: () => ({
      push: spies.push ?? vi.fn(),
      refresh: spies.refresh ?? vi.fn(),
      back: vi.fn(),
    }),
    useParams: () => params,
  };
}

/**
 * DI container mock that resolves the given tokens to their stubs and throws on
 * anything unexpected — mirroring the real container. The list page resolves
 * `BackOfficeBankAccountCrudRepository` / `BackOfficeBankAccountResourceNavigator`
 * directly (unlike banks, which synthesizes them), so specs bind those keys to a
 * `{ search, find, delete }` / `{ follow }` stub.
 */
export function containerMock(handlers: Record<string, object>) {
  return {
    container: {
      get: (token: string) => {
        const handler = handlers[token];
        if (handler) return handler;
        throw new Error(`Unexpected DI token ${token}`);
      },
    },
  };
}

/** Toast notifier port mock — all four channels are spies. */
export function toastNotifierMock() {
  return {
    toastNotifier: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
  };
}

/**
 * `bankAccountRealtime` mock that keeps the REAL `bankAccountTopics` (so the
 * topic IRIs can never drift from production) and replaces only
 * `useBankAccountRealtime`. Pass a `capture` callback to grab the handlers and
 * drive Mercure events directly from a test; omit it to neutralise the
 * subscription entirely.
 */
export async function bankAccountRealtimeMock(
  capture?: (handlers: BankAccountRealtimeHandlers) => void,
) {
  const actual = await vi.importActual<
    typeof import("@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime")
  >("@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime");
  return {
    ...actual,
    useBankAccountRealtime: capture
      ? (_topics: readonly string[], handlers: BankAccountRealtimeHandlers): void => {
          capture(handlers);
        }
      : vi.fn(),
  };
}
