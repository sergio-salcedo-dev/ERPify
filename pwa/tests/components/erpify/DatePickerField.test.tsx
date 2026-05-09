import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { DatePickerField } from "@/components/erpify/DatePickerField";

describe("DatePickerField", () => {
  it("renders a native date picker (input[type=date]) with the given value", () => {
    render(
      <DatePickerField
        name="created-from"
        label="Created from"
        value="2026-04-15"
        onChange={() => undefined}
        testId="picker-from"
      />,
    );
    const input = screen.getByTestId("picker-from");
    expect(input).toHaveAttribute("type", "date");
    expect(input).toHaveValue("2026-04-15");
  });

  it("renders the visible label without appending a format hint", () => {
    render(
      <DatePickerField
        name="created-from"
        label="Created from"
        value=""
        onChange={() => undefined}
      />,
    );
    expect(screen.getByText("Created from")).toBeInTheDocument();
    // The native picker provides the format affordance — no "(dd/mm/yyyy)" hint.
    expect(screen.queryByText(/dd\/mm\/yyyy/i)).toBeNull();
  });

  it("forwards the picker's value to onChange", () => {
    const onChange = vi.fn();
    render(
      <DatePickerField
        name="created-from"
        label="Created from"
        value=""
        onChange={onChange}
        testId="picker-from"
      />,
    );
    fireEvent.change(screen.getByTestId("picker-from"), { target: { value: "2026-04-15" } });
    expect(onChange).toHaveBeenCalledWith("2026-04-15");
  });

  it("forwards `min` / `max` bounds to the native input", () => {
    render(
      <DatePickerField
        name="created-to"
        label="Created to"
        value=""
        onChange={() => undefined}
        min="2026-01-01"
        max="2026-12-31"
        testId="picker-to"
      />,
    );
    const input = screen.getByTestId("picker-to");
    expect(input).toHaveAttribute("min", "2026-01-01");
    expect(input).toHaveAttribute("max", "2026-12-31");
  });
});
