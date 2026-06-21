import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { AsyncBoundary } from "@/components/erpify";
import { ViewStatus } from "@/context/shared/view-state/domain/ViewState";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

const problem: ProblemDetails = {
  type: "about:blank",
  title: "Unexpected error",
  status: 0,
  detail: "boom",
  instance: "i",
  "correlation-id": "c",
};

describe("AsyncBoundary — errorAction", () => {
  it("renders the errorAction node in the error state", () => {
    render(
      <AsyncBoundary
        state={ViewStatus.ERROR}
        data={[]}
        error={problem}
        errorAction={<button data-testid="retry">Retry</button>}
      >
        {() => <div>data</div>}
      </AsyncBoundary>,
    );
    expect(screen.getByTestId("retry")).toBeInTheDocument();
  });

  it("renders nothing extra in the error state when no errorAction is given", () => {
    render(
      <AsyncBoundary state={ViewStatus.ERROR} data={[]} error={problem}>
        {() => <div>data</div>}
      </AsyncBoundary>,
    );
    expect(screen.queryByTestId("retry")).not.toBeInTheDocument();
  });
});
