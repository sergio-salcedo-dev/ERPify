import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { RedactedValue } from "@/context/backoffice/audit/infrastructure/ui/RedactedValue";

describe("RedactedValue", () => {
  it("renders the [REDACTED] sentinel verbatim with no copy affordance", () => {
    render(<RedactedValue testId="ip-redacted" />);
    expect(screen.getByText("[REDACTED]")).toBeInTheDocument();
    expect(screen.queryByRole("button")).toBeNull();
  });
});
