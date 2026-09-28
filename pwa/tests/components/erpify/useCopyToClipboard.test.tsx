import { afterEach, describe, expect, it, vi } from "vitest";
import { act, renderHook, waitFor } from "@testing-library/react";
import { useCopyToClipboard } from "@/components/erpify/useCopyToClipboard";

describe("useCopyToClipboard", () => {
  afterEach(() => {
    vi.restoreAllMocks();
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
