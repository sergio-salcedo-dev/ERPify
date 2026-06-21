import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { AsyncBoundary } from "@/components/erpify/AsyncBoundary";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

const problem: ProblemDetails = {
  type: "internal-error",
  title: "Internal server error",
  status: 500,
  instance: "01926e7e-7b8a-7c4e-9f31-a2b7d1e4f5c6",
  "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000001",
};

describe("AsyncBoundary", () => {
  it("renders the loading skeleton with aria-busy when state=loading", () => {
    const { container } = render(
      <AsyncBoundary state="loading">{() => <div>data</div>}</AsyncBoundary>,
    );
    const wrapper = container.querySelector('[data-async-state="loading"]');
    expect(wrapper).toBeTruthy();
    expect(wrapper).toHaveAttribute("aria-busy", "true");
  });

  it("renders the empty state when state=empty", () => {
    render(
      <AsyncBoundary
        state="empty"
        emptyVariant="filtered-to-zero"
        emptyHeading="No matches"
        emptyDescription="Clear filters."
      >
        {() => <div>data</div>}
      </AsyncBoundary>,
    );
    expect(screen.getByRole("heading", { name: "No matches" })).toBeInTheDocument();
  });

  it("renders ProblemDisplay when state=error and an error is provided", () => {
    render(
      <AsyncBoundary state="error" error={problem}>
        {() => <div>data</div>}
      </AsyncBoundary>,
    );
    expect(screen.getByText("Internal server error")).toBeInTheDocument();
  });

  it("invokes children with data when state=ready", () => {
    render(
      <AsyncBoundary state="ready" data={{ ok: true }}>
        {(d) => <div>{d.ok ? "yes" : "no"}</div>}
      </AsyncBoundary>,
    );
    expect(screen.getByText("yes")).toBeInTheDocument();
  });

  it("renders nothing when state=ready but no data is supplied", () => {
    const { container } = render(<AsyncBoundary state="ready">{() => <div>x</div>}</AsyncBoundary>);
    expect(container).toBeEmptyDOMElement();
  });

  it("renders custom idle slot when supplied", () => {
    render(
      <AsyncBoundary state="idle" idle={<span>waiting…</span>}>
        {() => <div>data</div>}
      </AsyncBoundary>,
    );
    expect(screen.getByText("waiting…")).toBeInTheDocument();
  });
});
