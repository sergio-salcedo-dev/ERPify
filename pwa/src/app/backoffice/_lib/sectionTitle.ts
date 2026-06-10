import { Routes } from "@/context/shared/domain/types/routes";
import { sectionTitleRules } from "@/app/backoffice/_lib/backofficeMenu";

/**
 * `[path, title]` rules sorted longest-match-first so nested routes
 * (e.g. `/profile/settings`, `/companies/employees`) resolve before their
 * parent prefix. The `localeCompare` tie-break makes the order total (and
 * deterministic across JS engines) for the many equal-length paths, so the
 * rule set never depends on unspecified sort behaviour. The rules themselves
 * are derived from the navigation model in `backofficeMenu`, so titles and
 * menu can never drift.
 */
const SECTION_RULES: ReadonlyArray<readonly [string, string]> = [...sectionTitleRules].sort(
  (a, b) => b[0].length - a[0].length || a[0].localeCompare(b[0]),
);

export function sectionTitleFor(pathname: string): string {
  if (pathname === Routes.BACKOFFICE) return "Dashboard";
  const match = SECTION_RULES.find(
    ([prefix]) => pathname === prefix || pathname.startsWith(`${prefix}/`),
  );
  return match ? match[1] : "Backoffice";
}
