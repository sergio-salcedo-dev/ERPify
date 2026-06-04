import { afterEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { MutationError } from "@/components/erpify/MutationError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { NodeEnv } from "@/context/shared/domain/types/nodeEnv";

const IN_USE_PROBLEM: ProblemDetails = {
  type: "bank-in-use",
  title: "Bank cannot be deleted: 12 associated bank accounts",
  status: 409,
  detail: "Remove or reassign the associated bank accounts first.",
  instance: "01926e7e-7b8a-7c4e-9f31-a2b7d1e4f5c6",
  "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000001",
  bankId: "01926e7e-7b8a-7c4e-9f31-b1c2d3e4f5a6",
  accountCount: 12,
};

function mockClipboard() {
  const writeText = vi.fn().mockResolvedValue(undefined);
  Object.assign(navigator, { clipboard: { writeText } });
  return writeText;
}

describe("MutationError", () => {
  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllEnvs();
  });

  it("renders the problem verbatim through ProblemDisplay and takes focus on mount", () => {
    render(<MutationError problem={IN_USE_PROBLEM} onDismiss={vi.fn()} testId="mutation-error" />);

    expect(screen.getByTestId("problem-display__title")).toHaveTextContent(IN_USE_PROBLEM.title);
    expect(screen.getByTestId("problem-display__status")).toHaveTextContent("HTTP 409");
    expect(screen.getByTestId("problem-display__type")).toHaveTextContent("bank-in-use");
    expect(screen.getByTestId("mutation-error")).toHaveFocus();
  });

  it("does not steal focus when focusOnMount is disabled (showcase surfaces)", () => {
    render(
      <MutationError
        problem={IN_USE_PROBLEM}
        onDismiss={vi.fn()}
        focusOnMount={false}
        testId="mutation-error"
      />,
    );
    expect(screen.getByTestId("mutation-error")).not.toHaveFocus();
  });

  it("re-focuses the surface when a retry replaces the problem", () => {
    const { rerender } = render(
      <MutationError problem={IN_USE_PROBLEM} onDismiss={vi.fn()} testId="mutation-error" />,
    );
    screen.getByTestId("mutation-error__dismiss").focus();

    const replaced = { ...IN_USE_PROBLEM, instance: "01926e7e-7b8a-7c4e-9f31-replacement00" };
    rerender(<MutationError problem={replaced} onDismiss={vi.fn()} testId="mutation-error" />);

    expect(screen.getByTestId("mutation-error")).toHaveFocus();
  });

  it("calls onDismiss from the × control", () => {
    const onDismiss = vi.fn();
    render(
      <MutationError problem={IN_USE_PROBLEM} onDismiss={onDismiss} testId="mutation-error" />,
    );

    fireEvent.click(screen.getByTestId("mutation-error__dismiss"));

    expect(onDismiss).toHaveBeenCalledTimes(1);
  });

  it("renders the typed recovery action in the action slot", () => {
    render(
      <MutationError
        problem={IN_USE_PROBLEM}
        onDismiss={vi.fn()}
        action={<button type="button">Refresh list</button>}
        testId="mutation-error"
      />,
    );
    expect(screen.getByRole("button", { name: "Refresh list" })).toBeInTheDocument();
  });

  it("copies the human message (title + detail) verbatim", async () => {
    const writeText = mockClipboard();
    render(<MutationError problem={IN_USE_PROBLEM} onDismiss={vi.fn()} testId="mutation-error" />);

    fireEvent.click(screen.getByTestId("mutation-error__copy-message"));

    await waitFor(() => {
      expect(writeText).toHaveBeenCalledWith(
        [IN_USE_PROBLEM.title, IN_USE_PROBLEM.detail].filter(Boolean).join("\n"),
      );
    });
  });

  it("copies the error code as `type · status`", async () => {
    const writeText = mockClipboard();
    render(<MutationError problem={IN_USE_PROBLEM} onDismiss={vi.fn()} testId="mutation-error" />);

    fireEvent.click(screen.getByTestId("mutation-error__copy-code"));

    await waitFor(() => {
      expect(writeText).toHaveBeenCalledWith("bank-in-use · 409");
    });
  });

  it("copies the full payload as received, extensions included", async () => {
    const writeText = mockClipboard();
    render(<MutationError problem={IN_USE_PROBLEM} onDismiss={vi.fn()} testId="mutation-error" />);

    fireEvent.click(screen.getByTestId("mutation-error__copy-json"));

    await waitFor(() => {
      expect(writeText).toHaveBeenCalledTimes(1);
    });
    const copied = JSON.parse(writeText.mock.calls[0][0] as string) as Record<string, unknown>;
    expect(copied).toEqual(IN_USE_PROBLEM);
    expect(copied.accountCount).toBe(12);
  });

  it("strips `debug` from the copied JSON in production builds — render/copy parity", async () => {
    vi.stubEnv("NODE_ENV", NodeEnv.PRODUCTION);
    const writeText = mockClipboard();
    const leaked = {
      ...IN_USE_PROBLEM,
      debug: { exception_class: "RuntimeException", message: "secret stack" },
    };
    render(<MutationError problem={leaked} onDismiss={vi.fn()} testId="mutation-error" />);

    fireEvent.click(screen.getByTestId("mutation-error__copy-json"));

    await waitFor(() => {
      expect(writeText).toHaveBeenCalledTimes(1);
    });
    const copied = JSON.parse(writeText.mock.calls[0][0] as string) as Record<string, unknown>;
    expect(copied.debug).toBeUndefined();
    expect(screen.queryByTestId("problem-display__debug")).toBeNull();
  });
});
