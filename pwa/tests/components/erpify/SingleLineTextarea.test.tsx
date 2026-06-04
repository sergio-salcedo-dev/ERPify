import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { SingleLineTextarea } from "@/components/erpify/SingleLineTextarea";

describe("SingleLineTextarea", () => {
  it("submits the enclosing form on Enter instead of inserting a line break", () => {
    const onSubmit = vi.fn((event: React.FormEvent) => event.preventDefault());
    render(
      <form onSubmit={onSubmit}>
        <SingleLineTextarea data-testid="field" />
      </form>,
    );
    const field = screen.getByTestId("field");
    fireEvent.keyDown(field, { key: "Enter" });
    expect(onSubmit).toHaveBeenCalledTimes(1);
    expect((field as HTMLTextAreaElement).value).not.toContain("\n");
  });

  it("collapses pasted newlines to spaces — visibly, never truncating", () => {
    const onChange = vi.fn();
    render(<SingleLineTextarea data-testid="field" onChange={onChange} />);
    const field = screen.getByTestId("field") as HTMLTextAreaElement;
    fireEvent.change(field, { target: { value: "Banco de\nCrédito y\n\nComercio" } });
    expect(field.value).toBe("Banco de Crédito y Comercio");
    expect(onChange).toHaveBeenCalledTimes(1);
  });

  it("never sets maxLength — limits belong to the schema where the error is visible", () => {
    render(<SingleLineTextarea data-testid="field" />);
    expect(screen.getByTestId("field")).not.toHaveAttribute("maxlength");
  });
});
