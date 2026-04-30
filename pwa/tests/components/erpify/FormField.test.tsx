import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { FormField } from "@/components/erpify/FormField";

describe("FormField", () => {
  it("links the label to the input via id and htmlFor", () => {
    render(
      <FormField name="email" label="Email">
        <input type="email" />
      </FormField>,
    );
    const input = screen.getByLabelText("Email");
    expect(input).toBeInTheDocument();
    expect(input.tagName).toBe("INPUT");
  });

  it("marks required fields with aria and visible indicator", () => {
    render(
      <FormField name="email" label="Email" required>
        <input type="email" />
      </FormField>,
    );
    const input = screen.getByLabelText(/Email/);
    expect(input).toHaveAttribute("aria-required", "true");
    expect(screen.getByText("(required)")).toBeInTheDocument();
  });

  it("renders helper text and links it via aria-describedby", () => {
    render(
      <FormField name="email" label="Email" helper="We'll never share it.">
        <input type="email" />
      </FormField>,
    );
    const input = screen.getByLabelText("Email");
    const describedBy = input.getAttribute("aria-describedby");
    expect(describedBy).toBeTruthy();
    const helperEl = document.getElementById(describedBy as string);
    expect(helperEl).toHaveTextContent("We'll never share it.");
  });

  it("renders the explicit error and sets aria-invalid", () => {
    render(
      <FormField name="email" label="Email" error="Invalid format">
        <input type="email" />
      </FormField>,
    );
    expect(screen.getByLabelText("Email")).toHaveAttribute("aria-invalid", "true");
    expect(screen.getByRole("alert")).toHaveTextContent("Invalid format");
  });

  it("resolves error from RFC 9457 violations[] when no explicit error is supplied", () => {
    const violations = [
      { field: "email", message: "must be a valid email", code: "Email" },
      { field: "name", message: "must not be blank", code: "NotBlank" },
    ];
    render(
      <FormField name="email" label="Email" violations={violations}>
        <input type="email" />
      </FormField>,
    );
    expect(screen.getByRole("alert")).toHaveTextContent("must be a valid email");
  });

  it("prefers explicit error over violations match", () => {
    const violations = [{ field: "email", message: "from violations", code: "X" }];
    render(
      <FormField name="email" label="Email" error="explicit error" violations={violations}>
        <input type="email" />
      </FormField>,
    );
    expect(screen.getByRole("alert")).toHaveTextContent("explicit error");
    expect(screen.queryByText("from violations")).not.toBeInTheDocument();
  });
});
