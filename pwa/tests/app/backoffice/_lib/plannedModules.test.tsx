import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { PlannedModules, isPlannedPath } from "@/app/backoffice/_lib/plannedModules";
import {
  accountMenuItem,
  backofficeMenuGroups,
  type NavItem,
} from "@/app/backoffice/_lib/backofficeMenu";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { roadmapModule, roadmapModuleAnchor } from "@/app/backoffice/roadmap/_lib/roadmap";
import { Routes } from "@/context/shared/routing/domain/Routes";

const APP_ROOT = resolve(__dirname, "../../../../src/app");

const MODULES = Object.entries(PlannedModules);

/** Parents that group nested routes but are not menu entries, reachable by URL truncation. */
const SECTION_LANDINGS: ReadonlySet<string> = new Set([
  `${Routes.BACKOFFICE}/catalog`,
  `${Routes.BACKOFFICE}/finance`,
  `${Routes.BACKOFFICE}/settings`,
]);

const MENU_PATHS: ReadonlySet<string> = new Set(
  [...backofficeMenuGroups.flatMap((group) => group.items), accountMenuItem as NavItem].flatMap(
    (item) => [item.path, ...(item.subItems ?? []).map((sub) => sub.path)],
  ),
);

function pageFileFor(path: string): string {
  return resolve(APP_ROOT, `.${path}`, "page.tsx");
}

describe("the planned-module registry", () => {
  it.each(MODULES)("%s points at a menu entry or a section landing", (_key, module) => {
    // A typo here would silently leave the real entry unmarked in the sidebar.
    expect(MENU_PATHS.has(module.path) || SECTION_LANDINGS.has(module.path)).toBe(true);
  });

  it.each(MODULES)("%s is the route that renders the planned page", (key, module) => {
    // Both directions: the route exists, and it is the one rendering THIS registry entry. A shipped
    // module whose line was not deleted would otherwise keep its "Soon" marker over a live page.
    const file = pageFileFor(module.path);
    expect(existsSync(file)).toBe(true);
    const source = readFileSync(file, "utf8");
    expect(source).toContain(`<PlannedModulePage module={PlannedModules.${key}} />`);
    expect(source).toContain(`title: "${module.heading}"`);
  });

  it.each(MODULES.filter(([, module]) => "roadmapCode" in module))(
    "%s names a roadmap module that exists",
    (_key, module) => {
      expect(roadmapModule((module as { roadmapCode: string }).roadmapCode)).toBeDefined();
    },
  );

  it("answers exactly, so a live child under a planned parent stays live", () => {
    expect(isPlannedPath(`${Routes.BACKOFFICE}/finance`)).toBe(true);
    expect(isPlannedPath(`${Routes.BACKOFFICE}/finance/unknown`)).toBe(false);
    expect(isPlannedPath(`${Routes.BACKOFFICE}/banks`)).toBe(false);
  });
});

describe("PlannedModulePage", () => {
  it("says the module is not available, what it will cover, and links to its roadmap card", () => {
    render(<PlannedModulePage module={PlannedModules.INVOICES} />);

    expect(screen.getByRole("heading", { level: 1, name: "Invoicing" })).toBeInTheDocument();
    expect(screen.getByTestId("planned-module__status")).toHaveTextContent("Not available yet");

    const needs = screen.getByRole("region", { name: "What it will cover" });
    const finance = roadmapModule("2.3");
    expect(
      within(needs)
        .getAllByRole("listitem")
        .map((item) => item.textContent),
    ).toEqual(finance?.userNeeds);

    expect(screen.getByTestId("planned-module__roadmap-link")).toHaveAttribute(
      "href",
      `${Routes.BACKOFFICE}/roadmap#${roadmapModuleAnchor("2.3")}`,
    );
    expect(screen.getByTestId("planned-module__dashboard-link")).toHaveAttribute(
      "href",
      Routes.BACKOFFICE,
    );
  });

  it("links to the roadmap page itself when the surface has no roadmap module", () => {
    render(<PlannedModulePage module={PlannedModules.SETTINGS} />);

    expect(screen.queryByTestId("planned-module__needs")).toBeNull();
    expect(screen.getByTestId("planned-module__roadmap-link")).toHaveAttribute(
      "href",
      `${Routes.BACKOFFICE}/roadmap`,
    );
  });
});
