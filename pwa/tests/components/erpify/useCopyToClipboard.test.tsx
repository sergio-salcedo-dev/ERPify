import { afterEach, describe, expect, it, vi } from "vitest";
import { act, renderHook, waitFor } from "@testing-library/react";
import { useCopyToClipboard } from "@/components/erpify/useCopyToClipboard";

describe("useCopyToClipboard", () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("clears the previous outcome while a new copy is in flight, so an identical one is announced again", async () => {
    let settle: () => void = () => undefined;
    const writeText = vi
      .fn()
      .mockResolvedValueOnce(undefined)
      .mockImplementationOnce(
        () =>
          new Promise<void>((resolve) => {
            settle = resolve;
          }),
      );
    Object.assign(navigator, { clipboard: { writeText } });

    const { result } = renderHook(() => useCopyToClipboard("x"));
    await act(async () => {
      await result.current.copy();
    });
    expect(result.current.status).toBe("copied");

    let second: Promise<void> = Promise.resolve();
    act(() => {
      second = result.current.copy();
    });
    expect(result.current.status).toBe("idle");

    await act(async () => {
      settle();
      await second;
    });
    expect(result.current.status).toBe("copied");
  });

  it("still returns to idle when the result callback throws", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });
    const onCopyResult = vi.fn(() => {
      throw new Error("callback blew up");
    });

    const { result } = renderHook(() =>
      useCopyToClipboard("x", { feedbackTimeoutMs: 50, onCopyResult }),
    );
    await act(async () => {
      await expect(result.current.copy()).rejects.toThrow("callback blew up");
    });

    expect(result.current.status).toBe("copied");
    await waitFor(
      () => {
        expect(result.current.status).toBe("idle");
      },
      { timeout: 1000 },
    );
  });
});
