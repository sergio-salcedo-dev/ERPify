import { vi } from "vitest";
import type { BankRealtimeHandlers } from "@/context/backoffice/bank/infrastructure/bankRealtime";

/**
 * Shared `vi.mock` factories for the banks test suite. Each test still declares
 * its own `vi.mock(...)` call (vitest hoists those per file), but the factory
 * body is centralised here so the DI-container and toast boilerplate isn't
 * copy-pasted across every spec.
 */

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
 * DI container mock that resolves the given tokens to their stubs and throws on
 * anything unexpected — mirroring the real container's behaviour. Handlers are
 * typed loosely (a repository exposes `search`/`find`/`delete`, a navigator
 * `follow`, a use case `run`); call sites recover the precise type through
 * `container.get<T>(token)`.
 *
 * Bind each token in the role the page resolves it. The resource toolkit reads
 * and bulk-mutates through `BackOfficeBankCrudRepository` (`search` for a page
 * load, `find` + `delete` for the bulk pre-probe and removal) and paginates
 * through `BackOfficeBankResourceNavigator` (`follow`) — both already in the
 * generic `{ items }` page shape — while a row's own delete button resolves the
 * `BackOfficeDeleteBank` use case directly, so a spec exercising both delete
 * paths points them at the same spy. Leaving `BackOfficeCountBanks` unbound is a
 * supported case: `useBanksCount` swallows the resolution failure and the header
 * total stays at its default.
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
 * Complete mock kit for `BankForm` specs: the navigation, DI-container
 * (create + update use cases) and toast module substitutes, plus the spies
 * they are wired to. Build it inside `vi.hoisted` and hand each module to the
 * matching `vi.mock`:
 *
 * ```ts
 * const mocks = await vi.hoisted(async () => (await import("./_mocks")).bankFormMocks());
 * vi.mock("next/navigation", () => mocks.navigation);
 * ```
 */
export function bankFormMocks() {
  const spies = { push: vi.fn(), refresh: vi.fn(), createRun: vi.fn(), updateRun: vi.fn() };
  return {
    ...spies,
    navigation: routerMock(spies),
    container: containerMock({
      BackOfficeCreateBank: { run: spies.createRun },
      BackOfficeUpdateBank: { run: spies.updateRun },
    }),
    toast: toastNotifierMock(),
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

/**
 * Complete mock kit for `BanksListPage` specs: each field named after a module
 * is the ready-made `vi.mock` FACTORY for it (navigation, DI container wired to
 * the tokens the page resolves, toast, `bankRealtime`), alongside the spies they
 * resolve to. The page's realtime handlers land on `realtime.handlers`, so specs
 * can drive Mercure events directly (reset it in `beforeEach`). Build the kit
 * inside `vi.hoisted` and hand each factory to the matching `vi.mock`:
 *
 * ```ts
 * const mocks = await vi.hoisted(async () => (await import("./_mocks")).banksListPageMocks());
 * vi.mock("next/navigation", mocks.navigation);
 * vi.mock("@/context/shared/dependency-injection/infrastructure/Container", mocks.container);
 * vi.mock("@/context/shared/notification/infrastructure/Toast", mocks.toast);
 * vi.mock("@/context/backoffice/bank/infrastructure/bankRealtime", mocks.bankRealtime);
 * ```
 */
export function banksListPageMocks() {
  const spies = { searchRun: vi.fn(), deleteRun: vi.fn(), findRun: vi.fn() };
  const realtime: { handlers: BankRealtimeHandlers | undefined } = { handlers: undefined };
  return {
    ...spies,
    realtime,
    navigation: () => routerMock(),
    container: () =>
      containerMock({
        BackOfficeBankCrudRepository: {
          search: spies.searchRun,
          find: spies.findRun,
          delete: spies.deleteRun,
        },
        BackOfficeDeleteBank: { run: spies.deleteRun },
      }),
    toast: () => toastNotifierMock(),
    bankRealtime: () =>
      bankRealtimeMock((handlers) => {
        realtime.handlers = handlers;
      }),
  };
}
