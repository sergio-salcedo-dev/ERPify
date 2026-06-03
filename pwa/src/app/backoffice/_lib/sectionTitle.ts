import { Routes } from "@/context/shared/domain/types/routes";
import { bankRoutes } from "@/app/backoffice/banks/_lib/bankRoutes";

/**
 * Maps a backoffice pathname to the section title shown in the top bar.
 * Ordered longest-match-first so profile sub-routes resolve before the profile root.
 */
const SECTION_RULES: ReadonlyArray<readonly [string, string]> = [
  [bankRoutes.list, "Banks"],
  [`${Routes.BACKOFFICE}/health`, "Service Health"],
  [`${Routes.BACKOFFICE}/administration`, "Administration"],
  [`${Routes.BACKOFFICE}/profile/notifications`, "Notifications"],
  [`${Routes.BACKOFFICE}/profile/settings`, "Settings"],
  [`${Routes.BACKOFFICE}/profile`, "User Profile"],
];

export function sectionTitleFor(pathname: string): string {
  if (pathname === Routes.BACKOFFICE) return "Dashboard";
  const match = SECTION_RULES.find(
    ([prefix]) => pathname === prefix || pathname.startsWith(`${prefix}/`),
  );
  return match ? match[1] : "Backoffice";
}
