import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { ConnectivityButton } from "@/context/shared/connectivity/infrastructure/ui/ConnectivityButton";

describe("ConnectivityButton", () => {
  it("renders its children and is enabled when idle", () => {
    render(
      <ConnectivityButton loading={false} testId="cta">
        Activar cuenta
      </ConnectivityButton>,
    );
    const button = screen.getByRole("button", { name: "Activar cuenta" });
    expect(button).toBeEnabled();
    expect(button).not.toHaveAttribute("aria-busy");
  });

  it("shows «Enviando…», marks aria-busy, and disables while in flight (blocks double submit)", () => {
    render(
      <ConnectivityButton loading testId="cta">
        Activar cuenta
      </ConnectivityButton>,
    );
    const button = screen.getByRole("button", { name: "Enviando…" });
    expect(button).toBeDisabled();
    expect(button).toHaveAttribute("aria-busy", "true");
  });

  it("is disabled when the caller gates it (offline) even while idle", () => {
    render(
      <ConnectivityButton loading={false} disabled testId="cta">
        Activar cuenta
      </ConnectivityButton>,
    );
    expect(screen.getByRole("button", { name: "Activar cuenta" })).toBeDisabled();
  });
});
