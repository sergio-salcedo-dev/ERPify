import { Routes } from "@/context/shared/domain/types/routes";
import { bankRoutes } from "@/app/backoffice/banks/_lib/bankRoutes";
import { erpSectionRules } from "@/app/backoffice/_lib/erpMenu";

/**
 * Maps a backoffice pathname to the section title shown in the top bar.
 * Sorted longest-match-first so nested routes (e.g. `/profile/settings`,
 * `/companies/employees`) resolve before their parent prefix.
 */
const SECTION_RULES: ReadonlyArray<readonly [string, string]> = [
  [bankRoutes.list, "Banks"] as const,
  [`${Routes.BACKOFFICE}/health`, "Service Health"] as const,
  [`${Routes.BACKOFFICE}/administration`, "Administration"] as const,
  [`${Routes.BACKOFFICE}/profile/notifications`, "Notifications"] as const,
  [`${Routes.BACKOFFICE}/profile/settings`, "Settings"] as const,
  [`${Routes.BACKOFFICE}/profile`, "User Profile"] as const,
  ...erpSectionRules,
].sort((a, b) => b[0].length - a[0].length);

export function sectionTitleFor(pathname: string): string {
  if (pathname === Routes.BACKOFFICE) return "Dashboard";
  const match = SECTION_RULES.find(
    ([prefix]) => pathname === prefix || pathname.startsWith(`${prefix}/`),
  );
  return match ? match[1] : "Backoffice";
}
