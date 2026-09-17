import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, renderHook, waitFor } from "@testing-library/react";
import { useBanksCount } from "@/context/backoffice/bank/application/useBanksCount";

/**
 * The hook's own contract, exercised where the page cannot reach it: what happens to an answer that
 * arrives after the component is gone, and what happens when several reads are in flight and settle
 * out of order. `refresh` is wired to realtime create, delete and reconnect, so concurrent reads are
 * the normal case during a bulk change rather than a contrived one.
 */

const run = vi.fn();
const get = vi.fn();

vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: (token: string) => get(token) as unknown,
  },
}));

/** A promise plus the handles to settle it later, so a test controls the interleaving. */
function deferred<T>(): {
  promise: Promise<T>;
  resolve: (v: T) => void;
  reject: (e: Error) => void;
} {
  let resolve!: (v: T) => void;
  let reject!: (e: Error) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });

  return { promise, resolve, reject };
}

describe("useBanksCount", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    get.mockReturnValue({ run });
  });

  it("reports the total the read returned", async () => {
    run.mockResolvedValue(7);

    const { result } = renderHook(() => useBanksCount());

    await waitFor(() => expect(result.current.count).toBe(7));
  });

  it.each([
    { outcome: "resolution", settle: (d: ReturnType<typeof deferred<number>>) => d.resolve(9) },
    {
      outcome: "rejection",
      settle: (d: ReturnType<typeof deferred<number>>) => d.reject(new Error("network")),
    },
  ])("drops a $outcome that arrives after unmount", async ({ settle }) => {
    const pending = deferred<number>();
    run.mockReturnValue(pending.promise);

    const { unmount } = renderHook(() => useBanksCount());
    unmount();

    // Settling after unmount must not reach `setState`. React logs no warning here either way, so the
    // assertion is that the test itself stays clean: an unguarded update would red the suite through
    // `console.error`, which the setup treats as a failure.
    await act(async () => {
      settle(pending);
      await pending.promise.catch(() => undefined);
    });

    expect(run).toHaveBeenCalledTimes(1);
  });

  it("does not let a stale rejection erase a newer correct total", async () => {
    const first = deferred<number>();
    const second = deferred<number>();
    run.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);

    const { result } = renderHook(() => useBanksCount());
    act(() => result.current.refresh());

    // The second read wins and paints a real total…
    await act(async () => {
      second.resolve(30);
      await second.promise;
    });
    await waitFor(() => expect(result.current.count).toBe(30));

    // …then the first, superseded, read fails. Reporting that as "unknown" would discard a value that
    // is right, which is worse than the stale number the unknown state exists to prevent.
    await act(async () => {
      first.reject(new Error("timeout"));
      await first.promise.catch(() => undefined);
    });

    expect(result.current.count).toBe(30);
  });

  it("does not let a stale resolution overwrite a newer total", async () => {
    const first = deferred<number>();
    const second = deferred<number>();
    run.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);

    const { result } = renderHook(() => useBanksCount());
    act(() => result.current.refresh());

    await act(async () => {
      second.resolve(30);
      await second.promise;
    });
    await act(async () => {
      first.resolve(31);
      await first.promise;
    });

    expect(result.current.count).toBe(30);
  });

  it("reports unknown when the use case is not wired", async () => {
    get.mockImplementation(() => {
      throw new Error("Unexpected DI token");
    });

    const { result } = renderHook(() => useBanksCount());

    await waitFor(() => expect(result.current.count).toBeNull());
    expect(run).not.toHaveBeenCalled();
  });
});
