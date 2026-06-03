import { vi } from "vitest";
import type { BankRealtimeHandlers } from "@/context/backoffice/bank/infrastructure/bankRealtime";

/**
 * Shared `vi.mock` factories for the banks test suite. Each test still declares
 * its own `vi.mock(...)` call (vitest hoists those per file), but the factory
 * body is centralised here so the DI-container and toast boilerplate isn't
 * copy-pasted across every spec.
 */

type RunStub = { run: ReturnType<typeof vi.fn> };

/**
 * `next/navigation` mock. Pass captured spies to assert on navigation; omitted
 * entries default to throwaway spies.
 */
export function routerMock(
  spies: { push?: ReturnType<typeof vi.fn>; refresh?: ReturnType<typeof vi.fn> } = {},
) {
  return {
    useRouter: () => ({
      push: spies.push ?? vi.fn(),
      refresh: spies.refresh ?? vi.fn(),
      back: vi.fn(),
    }),
  };
}

/**
 * DI container mock that resolves the given tokens to their use-case stubs and
 * throws on anything unexpected — mirroring the real container's behaviour.
 */
export function containerMock(handlers: Record<string, RunStub>) {
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
 * `bankRealtime` mock that keeps the REAL `bankTopics` (so the topic IRI can
 * never drift from production) and replaces only `useBankRealtime`. Pass a
 * `capture` callback to grab the handlers and drive Mercure events directly from
 * a test; omit it to neutralise the subscription entirely.
 */
export async function bankRealtimeMock(capture?: (handlers: BankRealtimeHandlers) => void) {
  const actual = await vi.importActual<
    typeof import("@/context/backoffice/bank/infrastructure/bankRealtime")
  >("@/context/backoffice/bank/infrastructure/bankRealtime");
  return {
    ...actual,
    useBankRealtime: capture
      ? (_topics: readonly string[], handlers: BankRealtimeHandlers): void => {
          capture(handlers);
        }
      : vi.fn(),
  };
}
