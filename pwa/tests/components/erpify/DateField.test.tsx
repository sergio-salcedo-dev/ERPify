import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { DateField } from "@/components/erpify/DateField";

describe("DateField", () => {
  it("renders a text input with dd/mm/yyyy placeholder, pattern, and tooltip", () => {
    render(
      <DateField
        name="filter-from"
        label="Created from"
        value=""
        onChange={() => undefined}
        testId="filter-from-input"
      />,
    );
    const input = screen.getByTestId("filter-from-input");
    expect(input).toHaveAttribute("type", "text");
    expect(input).toHaveAttribute("placeholder", "dd/mm/yyyy");
    expect(input).toHaveAttribute("pattern", String.raw`\d{2}/\d{2}/\d{4}`);
    expect(input).toHaveAttribute("maxlength", "10");
    expect(input).toHaveAttribute("inputmode", "numeric");
    expect(input).toHaveAttribute("title", expect.stringContaining("dd/mm/yyyy"));
  });

  it("appends a `(dd/mm/yyyy)` hint to the visible label by default", () => {
    render(
      <DateField name="created-from" label="Created from" value="" onChange={() => undefined} />,
    );
    expect(screen.getByText(/^Created from \(dd\/mm\/yyyy\)$/)).toBeInTheDocument();
  });

  it("can suppress the format hint via `appendFormatHint={false}`", () => {
    render(
      <DateField
        name="created-from"
        label="Created from"
        appendFormatHint={false}
        value=""
        onChange={() => undefined}
      />,
    );
    expect(screen.getByText("Created from")).toBeInTheDocument();
    expect(screen.queryByText(/dd\/mm\/yyyy/)).toBeNull();
  });

  it("forwards user input to the onChange callback as a plain string", () => {
    const onChange = vi.fn();
    render(
      <DateField
        name="filter-from"
        label="Created from"
        value=""
        onChange={onChange}
        testId="filter-from-input"
      />,
    );
    fireEvent.change(screen.getByTestId("filter-from-input"), { target: { value: "15/04/2026" } });
    expect(onChange).toHaveBeenCalledWith("15/04/2026");
  });
});
