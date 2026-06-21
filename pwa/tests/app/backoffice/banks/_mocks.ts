import { vi } from "vitest";
import type { BankRealtimeHandlers } from "@/context/backoffice/bank/infrastructure/bankRealtime";
import type { BankSearchPage } from "@/context/backoffice/bank/domain/BankRepository";
import { toResourcePage } from "@/context/backoffice/bank/infrastructure/bankResourcePage";

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

function required<T>(fn: T | undefined, name: string): T {
  if (!fn) throw new Error(`${name} stub not provided to containerMock`);
  return fn;
}

function runOf(handler: object | undefined): ((arg: unknown) => Promise<unknown>) | undefined {
  return handler && "run" in handler
    ? (handler as { run: (arg: unknown) => Promise<unknown> }).run
    : undefined;
}

function followOf(handler: object | undefined): ((link: string) => Promise<unknown>) | undefined {
  return handler && "follow" in handler
    ? (handler as { follow: (link: string) => Promise<unknown> }).follow
    : undefined;
}

/**
 * Synthesizes the generic resource-toolkit adapter keys the banks list page now
 * resolves (`BackOfficeBankCrudRepository` / `BackOfficeBankResourceNavigator`)
 * from the bespoke use-case / navigator stubs a spec already wires up — mirroring
 * the production adapters (`{ banks }` → `{ items }` via `toResourcePage`). Specs
 * keep asserting on the same `searchRun`/`deleteRun`/`findRun`/`follow` spies.
 */
function synthesizeAdapter(token: string, handlers: Record<string, object>): object | undefined {
  if (token === "BackOfficeCountBanks") {
    // The list header reads the banks total through this use case. Specs that
    // don't care about the count get a stub resolving to 0; one that does wires
    // its own `BackOfficeCountBanks` stub, which `containerMock` returns first.
    return { run: async () => 0 };
  }
  if (token === "BackOfficeBankCrudRepository") {
    const search = runOf(handlers.BackOfficeSearchBanks);
    const find = runOf(handlers.BackOfficeFindBank);
    const remove = runOf(handlers.BackOfficeDeleteBank);
    if (!search && !find && !remove) return undefined;
    return {
      search: async (criteria: unknown) =>
        toResourcePage(
          (await required(search, "BackOfficeSearchBanks")(criteria)) as BankSearchPage,
        ),
      find: (id: string) => required(find, "BackOfficeFindBank")(id),
      delete: (id: string) => required(remove, "BackOfficeDeleteBank")(id),
    };
  }
  if (token === "BackOfficeBankResourceNavigator") {
    const follow = followOf(handlers.BackOfficeBankSearchNavigator);
    if (!follow) return undefined;
    return {
      follow: async (link: string) => toResourcePage((await follow(link)) as BankSearchPage),
    };
  }
  return undefined;
}

/**
 * DI container mock that resolves the given tokens to their use-case stubs and
 * throws on anything unexpected — mirroring the real container's behaviour.
 * Handlers are typed loosely (a use case may expose `run`, a navigator `follow`,
 * etc.); call sites recover the precise type through `container.get<T>(token)`.
 * The two generic bank resource-toolkit adapter keys are synthesized on demand
 * from the bespoke bank stubs, so list-page specs need not wire them explicitly.
 */
export function containerMock(handlers: Record<string, object>) {
  return {
    container: {
      get: (token: string) => {
        const handler = handlers[token];
        if (handler) return handler;
        const adapter = synthesizeAdapter(token, handlers);
        if (adapter) return adapter;
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
 * the search/delete/find use cases, toast, `bankRealtime`), alongside the spies
 * they resolve to. The page's realtime handlers land on `realtime.handlers`, so
 * specs can drive Mercure events directly (reset it in `beforeEach`). Build the
 * kit inside `vi.hoisted` and hand each factory to the matching `vi.mock`:
 *
 * ```ts
 * const mocks = await vi.hoisted(async () => (await import("./_mocks")).banksListPageMocks());
 * vi.mock("next/navigation", mocks.navigation);
 * vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", mocks.container);
 * vi.mock("@/context/shared/Notification/infrastructure/Toast", mocks.toast);
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
        BackOfficeSearchBanks: { run: spies.searchRun },
        BackOfficeDeleteBank: { run: spies.deleteRun },
        BackOfficeFindBank: { run: spies.findRun },
      }),
    toast: () => toastNotifierMock(),
    bankRealtime: () =>
      bankRealtimeMock((handlers) => {
        realtime.handlers = handlers;
      }),
  };
}
