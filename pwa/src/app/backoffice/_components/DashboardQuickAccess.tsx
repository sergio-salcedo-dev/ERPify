"use client";

import { useMemo } from "react";
import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { useSession } from "@/context/shared/access/application/useSession";
import { backofficeMenuGroups } from "@/app/backoffice/_lib/backofficeMenu";
import { permittedMenuGroups } from "@/app/backoffice/_lib/menuAccess";
import { goToDestinations, type GoToDestination } from "@/app/backoffice/_lib/goToDestinations";
import { Routes } from "@/context/shared/routing/domain/Routes";

const ROADMAP_PATH = `${Routes.BACKOFFICE}/roadmap`;

interface Section {
  label: string;
  destinations: GoToDestination[];
}

/** The live pages grouped by the sidebar group they sit in — the part before the first "›". */
function liveSections(destinations: readonly GoToDestination[]): Section[] {
  const sections = new Map<string, GoToDestination[]>();
  for (const destination of destinations) {
    if (destination.isPlanned || destination.path === Routes.BACKOFFICE) continue;
    const label = destination.section.split(" › ")[0];
    sections.set(label, [...(sections.get(label) ?? []), destination]);
  }
  return [...sections.entries()].map(([label, items]) => ({ label, destinations: items }));
}

/**
 * Where an operator can go from here, while the dashboard has no figures of its own: every page
 * that works today, grouped as the sidebar groups it and filtered by the same permissions, so the
 * first screen after sign-in is a starting point rather than an empty frame. Planned modules are
 * counted, not listed — the sidebar and the roadmap already show them.
 */
export function DashboardQuickAccess() {
  const { session } = useSession();
  const destinations = useMemo(
    () => goToDestinations(permittedMenuGroups(session, backofficeMenuGroups), [], ""),
    [session],
  );
  const sections = liveSections(destinations);
  const plannedCount = destinations.filter((destination) => destination.isPlanned).length;

  return (
    <section
      aria-labelledby="dashboard__quick-access-heading"
      className="dashboard__quick-access flex flex-col gap-3"
      data-testid="dashboard__quick-access"
    >
      <h2
        id="dashboard__quick-access-heading"
        className="dashboard__quick-access-heading text-foreground text-sm font-semibold"
      >
        Available now
      </h2>
      <div className="dashboard__quick-access-grid grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {sections.map((section) => (
          <nav
            key={section.label}
            aria-label={section.label}
            className="dashboard__quick-access-group bg-card border-border flex flex-col gap-1 rounded-lg border p-3"
          >
            <p className="dashboard__quick-access-label text-muted-foreground px-2 text-2xs font-semibold tracking-wider uppercase">
              {section.label}
            </p>
            <ul className="flex flex-col">
              {section.destinations.map((destination) => (
                <li key={destination.path}>
                  <Link
                    href={destination.path}
                    title={`Open ${destination.name}`}
                    className="dashboard__quick-access-link text-foreground hover:bg-accent focus-visible:ring-ring flex items-center justify-between gap-2 rounded-md px-2 py-1.5 text-sm font-medium outline-none focus-visible:ring-2"
                    data-testid={`dashboard__quick-access-link-${destination.path}`}
                  >
                    <span className="truncate">{destination.name}</span>
                    <ArrowRight
                      className="text-muted-foreground size-3.5 shrink-0"
                      aria-hidden="true"
                    />
                  </Link>
                </li>
              ))}
            </ul>
          </nav>
        ))}
      </div>
      {plannedCount > 0 ? (
        <p className="dashboard__quick-access-planned text-muted-foreground text-xs">
          {plannedCount === 1
            ? "1 more module is planned and marked “Soon” in the menu."
            : `${plannedCount} more modules are planned and marked “Soon” in the menu.`}{" "}
          <Link
            href={ROADMAP_PATH}
            className="text-foreground font-medium underline underline-offset-4"
            title="Open the product roadmap"
            data-testid="dashboard__quick-access-roadmap-link"
          >
            See the roadmap
          </Link>
        </p>
      ) : null}
    </section>
  );
}
