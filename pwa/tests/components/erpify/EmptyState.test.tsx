import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { Building2 } from "lucide-react";
import { EmptyState } from "@/components/erpify/EmptyState";

describe("EmptyState", () => {
  it("renders heading and description as accessible elements", () => {
    render(
      <EmptyState
        variant="first-run"
        heading="Create your first invoice"
        description="No invoices have been created yet."
      />,
    );
    expect(
      screen.getByRole("heading", { level: 3, name: "Create your first invoice" }),
    ).toBeInTheDocument();
    expect(screen.getByText("No invoices have been created yet.")).toBeInTheDocument();
  });

  it("exposes the variant via data attribute for testing and CSS hooks", () => {
    const { container } = render(
      <EmptyState
        variant="filtered-to-zero"
        heading="No matches"
        description="Try clearing filters."
      />,
    );
    expect(container.querySelector('[data-empty-variant="filtered-to-zero"]')).toBeTruthy();
  });

  it("renders the optional action slot when supplied", () => {
    render(
      <EmptyState
        variant="permission-denied"
        heading="No access"
        description="Contact your admin."
        action={<button>Request access</button>}
      />,
    );
    expect(screen.getByRole("button", { name: "Request access" })).toBeInTheDocument();
  });

  it("renders different icons across variants (different svg paths exposed)", () => {
    const { container: c1 } = render(
      <EmptyState variant="first-run" heading="A" description="B" />,
    );
    const { container: c2 } = render(
      <EmptyState variant="permission-denied" heading="A" description="B" />,
    );
    const path1 = c1.querySelector("svg")?.innerHTML;
    const path2 = c2.querySelector("svg")?.innerHTML;
    expect(path1).toBeTruthy();
    expect(path2).toBeTruthy();
    expect(path1).not.toEqual(path2);
  });

  it("lets a custom icon override the variant default", () => {
    const { container: def } = render(
      <EmptyState variant="first-run" heading="A" description="B" />,
    );
    const { container: custom } = render(
      <EmptyState variant="first-run" heading="A" description="B" icon={Building2} />,
    );
    expect(def.querySelector("svg")?.innerHTML).not.toEqual(custom.querySelector("svg")?.innerHTML);
  });
});
