import { describe, it, expect, beforeEach } from "vitest";
import { StrictMode } from "react";
import { render, screen, within } from "@testing-library/react";
import { RecoveryRedeemedNextStep } from "@/app/backoffice/_components/RecoveryRedeemedNextStep";
import BackOfficeDashboard from "@/app/backoffice/page";
import {
  clearRecoveryRedeemed,
  markRecoveryRedeemed,
  peekRecoveryRedeemed,
} from "@/context/backoffice/user/application/recoveryRedeemedNotice";

const NEXT_STEP =
  "Next: set a new password — use Forgot password if you no longer know it — then create a new recovery secret. The one you used is spent.";

beforeEach(() => {
  clearRecoveryRedeemed();
});

describe("<RecoveryRedeemedNextStep>", () => {
  it("shows the next steps once after a confirmed redemption, and consumes the mark", () => {
    markRecoveryRedeemed();

    const { unmount } = render(<RecoveryRedeemedNextStep />);

    const notice = screen.getByTestId("dashboard__recovery-next-step");
    expect(notice.querySelector("p")?.textContent).toBe(NEXT_STEP);
    // A named landmark, never a live region: it is read in order, not announced over the toast.
    expect(notice.tagName).toBe("ASIDE");
    expect(notice).toHaveAttribute("aria-label", "Next steps after recovery");
    expect(notice).not.toHaveAttribute("aria-live");
    expect(notice).not.toHaveAttribute("role");
    expect(within(notice).getByTestId("dashboard__recovery-next-step-link")).toHaveAttribute(
      "href",
      "/backoffice/profile",
    );
    // Changing the password from the profile needs the current one; Forgot password does not.
    const forgot = within(notice).getByTestId("dashboard__recovery-next-step-forgot-link");
    expect(forgot).toHaveTextContent("Forgot password");
    expect(forgot).toHaveAttribute("href", "/forgot-password");
    expect(peekRecoveryRedeemed()).toBe(false);

    unmount();
    render(<RecoveryRedeemedNextStep />);

    expect(screen.queryByTestId("dashboard__recovery-next-step")).not.toBeInTheDocument();
  });

  it("never claims the account is safe — the password is still the old one", () => {
    markRecoveryRedeemed();
    render(<RecoveryRedeemedNextStep />);

    const text = screen.getByTestId("dashboard__recovery-next-step").textContent ?? "";
    expect(text).not.toMatch(/secure|safe/i);
  });

  it("stays absent on an ordinary visit to the dashboard", () => {
    render(<BackOfficeDashboard />);

    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("Dashboard");
    expect(screen.queryByTestId("dashboard__recovery-next-step")).not.toBeInTheDocument();
  });

  it("survives StrictMode's double mount: shown, and the mark consumed", () => {
    markRecoveryRedeemed();
    render(
      <StrictMode>
        <RecoveryRedeemedNextStep />
      </StrictMode>,
    );

    expect(screen.getByTestId("dashboard__recovery-next-step")).toBeInTheDocument();
    expect(peekRecoveryRedeemed()).toBe(false);
  });

  it("is mounted by the dashboard page under its heading", () => {
    markRecoveryRedeemed();
    render(<BackOfficeDashboard />);

    const heading = screen.getByRole("heading", { level: 1 });
    const notice = screen.getByTestId("dashboard__recovery-next-step");
    expect(heading.compareDocumentPosition(notice) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });
});
