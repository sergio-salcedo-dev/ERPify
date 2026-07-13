import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { OfflineNotice } from "@/context/shared/connectivity/infrastructure/ui/OfflineNotice";

describe("OfflineNotice", () => {
  it("announces the offline copy through a polite live region", () => {
    render(<OfflineNotice testId="offline" />);

    const notice = screen.getByRole("status");
    expect(notice).toHaveAttribute("aria-live", "polite");
    expect(notice).toHaveTextContent("Sin conexión. Reintenta cuando recuperes señal.");
    expect(screen.getByTestId("offline")).toBe(notice);
  });
});
