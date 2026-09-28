import { afterEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { CopyButton } from "@/components/erpify/CopyButton";

describe("CopyButton", () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("renders the default label, idle status, and tooltip", () => {
    render(<CopyButton value="hello" />);
    const btn = screen.getByRole("button");
    expect(btn).toHaveAttribute("data-copy-status", "idle");
    expect(btn).toHaveAttribute("title", "Copy");
    expect(btn).toHaveTextContent("Copy");
  });

  it("writes the value to the clipboard and flips status to copied", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });

    render(<CopyButton value="bank-id-123" label="Copy bank ID" />);
    fireEvent.click(screen.getByRole("button"));

    await waitFor(() => {
      expect(writeText).toHaveBeenCalledWith("bank-id-123");
    });
    await waitFor(() => {
      expect(screen.getByRole("button")).toHaveAttribute("data-copy-status", "copied");
    });
    expect(screen.getByRole("button")).toHaveTextContent("Copied");
  });

  it("returns to idle after the feedback timeout elapses", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });

    render(<CopyButton value="x" feedbackTimeoutMs={50} />);
    fireEvent.click(screen.getByRole("button"));

    await waitFor(() => {
      expect(screen.getByRole("button")).toHaveAttribute("data-copy-status", "copied");
    });
    await waitFor(
      () => {
        expect(screen.getByRole("button")).toHaveAttribute("data-copy-status", "idle");
      },
      { timeout: 1000 },
    );
  });

  it("flips status to error and reports it via onCopyResult when the clipboard write fails", async () => {
    const writeText = vi.fn().mockRejectedValue(new Error("nope"));
    Object.assign(navigator, { clipboard: { writeText } });
    const onCopyResult = vi.fn();

    render(<CopyButton value="x" onCopyResult={onCopyResult} feedbackTimeoutMs={5000} />);
    fireEvent.click(screen.getByRole("button"));

    await waitFor(() => {
      expect(screen.getByRole("button")).toHaveAttribute("data-copy-status", "error");
    });
    expect(onCopyResult).toHaveBeenCalledWith("error");
    expect(screen.getByRole("button")).toHaveTextContent("Copy failed");
  });

  it.each([
    ["no clipboard at all", undefined],
    ["a clipboard without writeText", {}],
  ])(
    "reports an error rather than copying through the deprecated execCommand with %s",
    async (_case, clipboard) => {
      const originalClipboard = navigator.clipboard;
      const originalExecCommand = document.execCommand;
      const execCommand = vi.fn().mockReturnValue(true);
      Object.assign(navigator, { clipboard });
      Object.assign(document, { execCommand });
      const onCopyResult = vi.fn();

      try {
        render(<CopyButton value="x" onCopyResult={onCopyResult} feedbackTimeoutMs={5000} />);
        fireEvent.click(screen.getByRole("button"));

        await waitFor(() => {
          expect(screen.getByRole("button")).toHaveAttribute("data-copy-status", "error");
        });
        expect(onCopyResult).toHaveBeenCalledWith("error");
        expect(execCommand).not.toHaveBeenCalled();
      } finally {
        Object.assign(navigator, { clipboard: originalClipboard });
        Object.assign(document, { execCommand: originalExecCommand });
      }
    },
  );

  it("reports nothing and arms no timer when it unmounts before the write settles", async () => {
    let settle: () => void = () => undefined;
    const writeText = vi.fn(
      () =>
        new Promise<void>((resolve) => {
          settle = resolve;
        }),
    );
    Object.assign(navigator, { clipboard: { writeText } });
    const onCopyResult = vi.fn();
    const setTimeoutSpy = vi.spyOn(globalThis, "setTimeout");

    const { unmount } = render(<CopyButton value="x" onCopyResult={onCopyResult} />);
    fireEvent.click(screen.getByRole("button"));
    await waitFor(() => {
      expect(writeText).toHaveBeenCalled();
    });
    unmount();
    const timersBefore = setTimeoutSpy.mock.calls.length;
    settle();
    await Promise.resolve();
    await Promise.resolve();

    expect(onCopyResult).not.toHaveBeenCalled();
    expect(setTimeoutSpy.mock.calls.length).toBe(timersBefore);
  });

  it("announces its own result label through the shared announcer, keeping its name stable", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });

    render(<CopyButton value="x" iconOnly label="Copy bank ID" copiedLabel="ID copied" />);
    const button = screen.getByRole("button");
    fireEvent.click(button);

    await waitFor(() => {
      expect(document.querySelector("[data-live-announcer]")).toHaveTextContent("ID copied");
    });
    expect(button).toHaveAttribute("data-copy-status", "copied");
    expect(button).toHaveAccessibleName("Copy bank ID");
    expect(button.querySelector("[role='status']")).toBeNull();
  });

  it("uses sr-only text in icon-only mode and still announces the label", () => {
    render(<CopyButton value="x" iconOnly label="Copy bank ID" testId="banks-detail__copy-id" />);
    const btn = screen.getByTestId("banks-detail__copy-id");
    expect(btn).toHaveAccessibleName("Copy bank ID");
    expect(btn).toHaveAttribute("title", "Copy bank ID");
  });
});
