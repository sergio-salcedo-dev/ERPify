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

  it("uses sr-only text in icon-only mode and still announces the label", () => {
    render(<CopyButton value="x" iconOnly label="Copy bank ID" testId="banks-detail__copy-id" />);
    const btn = screen.getByTestId("banks-detail__copy-id");
    expect(btn).toHaveAccessibleName("Copy bank ID");
    expect(btn).toHaveAttribute("title", "Copy bank ID");
  });
});
