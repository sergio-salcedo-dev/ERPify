import { afterEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, renderHook, screen } from "@testing-library/react";
import { useIbanReveal } from "@/app/backoffice/bank-accounts/_components/useIbanReveal";
import { IbanCell } from "@/app/backoffice/banks/[id]/accounts/_components/IbanCell";

/**
 * The owner half of the IBAN reveal. `IbanCell` is covered separately and thoroughly — the mask, the
 * ten-second auto-hide, pointer-leave, blur, storage and console. What lives only here is what the
 * CELL cannot see: that at most one row is ever revealed, that a new page re-masks, and that the
 * callbacks keep their identity.
 *
 * The last of those looks like a detail and is the one with teeth. `IbanCell` keys its auto-hide
 * effect on `[revealed, onHide]`, so an `onHide` minted per render restarts the countdown every
 * render and a list that re-renders while a row is revealed NEVER re-masks — the failure is silent,
 * the IBAN simply stays on screen. Asserting the identity is the precondition; the last test asserts
 * the consequence, by re-rendering under fake timers and requiring the ten seconds to still elapse.
 */

const ROWS_PAGE_1 = [{ id: "a" }, { id: "b" }];
const IBAN = "ES9121000418450200051332";

afterEach(() => {
  vi.useRealTimers();
});

describe("useIbanReveal", () => {
  it("reveals at most one row: revealing a second re-masks the first", () => {
    const { result } = renderHook(() => useIbanReveal(ROWS_PAGE_1));

    act(() => result.current.reveal("a"));
    expect(result.current.revealedId).toBe("a");

    act(() => result.current.reveal("b"));
    expect(result.current.revealedId).toBe("b");
  });

  it("re-masks on hide", () => {
    const { result } = renderHook(() => useIbanReveal(ROWS_PAGE_1));

    act(() => result.current.reveal("a"));
    act(() => result.current.hide());

    expect(result.current.revealedId).toBeNull();
  });

  it("re-masks when the page changes, so paginating never carries a revealed IBAN over", () => {
    const { result, rerender } = renderHook(({ items }) => useIbanReveal(items), {
      initialProps: { items: ROWS_PAGE_1 },
    });

    act(() => result.current.reveal("a"));
    rerender({ items: [{ id: "c" }, { id: "d" }] });

    expect(result.current.revealedId).toBeNull();
  });

  it("re-masks on a new array of the same rows, which is what a realtime reconcile delivers", () => {
    const { result, rerender } = renderHook(({ items }) => useIbanReveal(items), {
      initialProps: { items: ROWS_PAGE_1 },
    });

    act(() => result.current.reveal("a"));
    // Same contents, new identity — the comparison is deliberately by reference, because a reconcile
    // that re-fetches the same page still means the data underneath the revealed row was replaced.
    rerender({ items: [...ROWS_PAGE_1] });

    expect(result.current.revealedId).toBeNull();
  });

  it("keeps a revealed row revealed across a re-render that does not change the page", () => {
    const { result, rerender } = renderHook(({ items }) => useIbanReveal(items), {
      initialProps: { items: ROWS_PAGE_1 },
    });

    act(() => result.current.reveal("a"));
    rerender({ items: ROWS_PAGE_1 });

    expect(result.current.revealedId).toBe("a");
  });

  it("hands out stable reveal/hide identities", () => {
    const { result, rerender } = renderHook(({ items }) => useIbanReveal(items), {
      initialProps: { items: ROWS_PAGE_1 },
    });
    const first = { reveal: result.current.reveal, hide: result.current.hide };

    act(() => result.current.reveal("a"));
    rerender({ items: ROWS_PAGE_1 });

    expect(result.current.reveal).toBe(first.reveal);
    expect(result.current.hide).toBe(first.hide);
  });

  it("auto-hide still fires when the list re-renders while a row is revealed", () => {
    vi.useFakeTimers();

    function Row({ nonce }: Readonly<{ nonce: number }>) {
      const { revealedId, reveal, hide } = useIbanReveal(ROWS_PAGE_1);
      return (
        <>
          <span data-testid="nonce">{nonce}</span>
          <IbanCell
            iban={IBAN}
            revealed={revealedId === "a"}
            onReveal={() => reveal("a")}
            onHide={hide}
          />
        </>
      );
    }

    const { rerender } = render(<Row nonce={0} />);
    fireEvent.click(screen.getByRole("button", { name: "Show IBAN" }));
    expect(screen.getByText(IBAN)).toBeInTheDocument();

    // Re-render repeatedly, as a live list does. An onHide minted per render would restart the
    // countdown on each of these and the value would never re-mask.
    for (let nonce = 1; nonce <= 5; nonce += 1) {
      rerender(<Row nonce={nonce} />);
      act(() => {
        vi.advanceTimersByTime(1_000);
      });
    }
    act(() => {
      vi.advanceTimersByTime(5_000);
    });

    expect(screen.queryByText(IBAN)).not.toBeInTheDocument();
  });
});
