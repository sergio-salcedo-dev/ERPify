import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";

import NotificationsPage, { metadata } from "@/app/backoffice/profile/notifications/page";

describe("Backoffice notifications page", () => {
  it("renders the notifications surface heading", () => {
    render(<NotificationsPage />);

    expect(screen.getByRole("heading", { level: 1, name: "Notifications" })).toBeInTheDocument();
  });

  it("declares the notifications page title in its metadata", () => {
    expect(metadata.title).toBe("Notifications");
  });
});
