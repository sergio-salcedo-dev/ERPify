import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { ProblemDisplay } from "@/components/erpify/ProblemDisplay";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";

const baseProblem: ProblemDetails = {
  type: "bank-not-found",
  title: "Bank not found",
  status: 404,
  detail: "No bank exists with that identifier.",
  instance: "01926e7e-7b8a-7c4e-9f31-a2b7d1e4f5c6",
  "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000001",
};

describe("ProblemDisplay", () => {
  it("renders title and detail verbatim from the API envelope", () => {
    render(<ProblemDisplay problem={baseProblem} />);
    expect(screen.getByText("Bank not found")).toBeInTheDocument();
    expect(screen.getByText("No bank exists with that identifier.")).toBeInTheDocument();
  });

  it("renders the correlation ID as a copyable chip", () => {
    render(<ProblemDisplay problem={baseProblem} />);
    expect(screen.getByRole("button", { name: /Copy correlation ID/ })).toBeInTheDocument();
  });

  it("renders violations attached to fields when present", () => {
    const violations = [
      { field: "name", message: "must not be blank", code: "NotBlank" },
      { field: "iban", message: "invalid format", code: "Iban" },
    ];
    render(<ProblemDisplay problem={{ ...baseProblem, status: 422, violations }} />);
    expect(screen.getByText("name")).toBeInTheDocument();
    expect(screen.getByText(/must not be blank/)).toBeInTheDocument();
    expect(screen.getByText("iban")).toBeInTheDocument();
    expect(screen.getByText(/invalid format/)).toBeInTheDocument();
  });

  it("uses aria-live='assertive' for 5xx errors", () => {
    const { container } = render(
      <ProblemDisplay problem={{ ...baseProblem, status: 500, title: "Internal server error" }} />,
    );
    expect(container.querySelector('[role="alert"]')).toHaveAttribute("aria-live", "assertive");
  });

  it("uses aria-live='polite' for 4xx errors", () => {
    const { container } = render(<ProblemDisplay problem={baseProblem} />);
    expect(container.querySelector('[role="alert"]')).toHaveAttribute("aria-live", "polite");
  });

  it("exposes the problem type via data attribute for routing/styling hooks", () => {
    const { container } = render(<ProblemDisplay problem={baseProblem} />);
    expect(container.querySelector('[data-problem-type="bank-not-found"]')).toBeTruthy();
  });
});
