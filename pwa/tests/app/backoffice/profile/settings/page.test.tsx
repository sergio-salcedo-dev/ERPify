import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";

import SettingsPage, { metadata } from "@/app/backoffice/profile/settings/page";

describe("Backoffice settings page", () => {
  it("renders the settings surface heading", () => {
    render(<SettingsPage />);

    expect(screen.getByRole("heading", { level: 1, name: "Settings" })).toBeInTheDocument();
  });

  it("declares the settings page title in its metadata", () => {
    // Distinct from `/backoffice/settings`, which also shows a "Settings" heading: two routes
    // resolving one title leave `document.title` unchanged, and the announcer silent.
    expect(metadata.title).toBe("Profile settings");
  });
});
