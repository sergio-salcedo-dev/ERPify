import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";

import SettingsPage, { metadata } from "@/app/backoffice/profile/settings/page";

describe("Backoffice settings page", () => {
  it("renders the settings surface heading", () => {
    render(<SettingsPage />);

    // The heading matches the route's title, so the tab and the page name one surface.
    expect(screen.getByRole("heading", { level: 1, name: "Profile settings" })).toBeInTheDocument();
  });

  it("declares the settings page title in its metadata", () => {
    // Distinct from `/backoffice/settings`, whose heading is "Settings": two routes
    // resolving one title leave `document.title` unchanged, and the announcer silent.
    expect(metadata.title).toBe("Profile settings");
  });
});
