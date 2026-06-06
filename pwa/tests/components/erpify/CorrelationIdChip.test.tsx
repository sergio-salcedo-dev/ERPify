import { afterEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { CorrelationIdChip } from "@/components/erpify/CorrelationIdChip";

describe("CorrelationIdChip", () => {
  afterEach(() => {
    vi.useRealTimers();
  });

  it("exposes the full ID via the accessible name and the visible chip", () => {
    render(<CorrelationIdChip id="01926e7e-7b8a-7c4e-9f31-a2b7d1e4f5c6" />);
    expect(screen.getByRole("button")).toHaveAccessibleName(
      /Copy correlation ID 01926e7e-7b8a-7c4e-9f31-a2b7d1e4f5c6/,
    );
    expect(screen.getByTestId("correlation-id-display")).toHaveTextContent(
      "01926e7e-7b8a-7c4e-9f31-a2b7d1e4f5c6",
    );
  });

  it("copies the full ID to the clipboard and announces 'Copied'", async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });

    render(<CorrelationIdChip id="01926e7e-7b8a-7c4e-9f31-a2b7d1e4f5c6" />);
    fireEvent.click(screen.getByRole("button"));

    await waitFor(() => {
      expect(writeText).toHaveBeenCalledWith("01926e7e-7b8a-7c4e-9f31-a2b7d1e4f5c6");
    });
    await waitFor(() => {
      expect(screen.getByRole("status")).toHaveTextContent("Copied");
    });
  });

  it("renders the optional label prefix when supplied", () => {
    render(<CorrelationIdChip id="abcdef0123456789" label="Error ID:" />);
    // The label reads as muted prose alongside — not inside — the copy token,
    // so the copy control's accessible name stays the id alone.
    expect(screen.getByText("Error ID:")).toBeInTheDocument();
    expect(screen.getByRole("button")).toHaveAccessibleName("Copy correlation ID abcdef0123456789");
  });

  it("does not truncate when the ID is short enough", () => {
    render(<CorrelationIdChip id="short-id" />);
    expect(screen.getByTestId("correlation-id-display")).toHaveTextContent("short-id");
  });
});
