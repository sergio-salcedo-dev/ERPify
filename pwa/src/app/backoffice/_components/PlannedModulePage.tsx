import Link from "next/link";
import { ArrowLeft, Check, Map as MapIcon } from "lucide-react";
import { StatusBadge } from "@/components/erpify";
import { cn } from "@/components/cn";
import { buttonVariants } from "@/components/ui/button-variants";
import { Routes } from "@/context/shared/routing/domain/Routes";
import type { PlannedModule } from "@/app/backoffice/_lib/plannedModules";
import { roadmapModule, roadmapModuleAnchor } from "@/app/backoffice/roadmap/_lib/roadmap";

const ROADMAP_PATH = `${Routes.BACKOFFICE}/roadmap`;

/**
 * The page a reserved route renders until its module is built.
 *
 * It says, in this order, the three things an operator who clicked a menu entry needs: that the
 * module does not exist yet (so nothing is broken and nothing was lost), what it will do once it
 * does (the roadmap module's user needs, read from the roadmap), and where to go instead. The
 * status is text inside a badge, never colour alone.
 */
export function PlannedModulePage({ module }: Readonly<{ module: PlannedModule }>) {
  const roadmap = module.roadmapCode === undefined ? undefined : roadmapModule(module.roadmapCode);

  return (
    <div className="planned-module flex max-w-3xl flex-col gap-6" data-testid="planned-module">
      <header className="planned-module__header flex flex-col gap-2">
        <div className="planned-module__title-row flex flex-wrap items-center gap-2">
          <h1
            className="planned-module__title text-foreground text-2xl font-semibold tracking-tight"
            data-testid="planned-module__title"
          >
            {module.heading}
          </h1>
          <StatusBadge
            variant="neutral"
            label="Not available yet"
            testId="planned-module__status"
          />
        </div>
        {module.summary ? (
          <p className="planned-module__summary text-foreground max-w-prose text-sm">
            {module.summary}
          </p>
        ) : null}
        <p className="planned-module__lead text-muted-foreground max-w-prose text-sm">
          This module is planned but not built yet, so there is nothing to create or edit here
          today. Nothing is broken and none of your data is affected.
        </p>
      </header>

      {roadmap ? (
        <section
          aria-labelledby="planned-module__needs-heading"
          className="planned-module__needs bg-card border-border flex flex-col gap-3 rounded-lg border p-4"
          data-testid="planned-module__needs"
        >
          <div className="flex flex-col gap-0.5">
            <h2
              id="planned-module__needs-heading"
              className="planned-module__needs-heading text-foreground text-sm font-semibold"
            >
              What it will cover
            </h2>
            <p className="planned-module__roadmap-ref text-muted-foreground text-xs">
              Roadmap module {roadmap.code} · {roadmap.name}
            </p>
          </div>
          <ul className="planned-module__needs-list flex flex-col gap-1.5">
            {roadmap.userNeeds.map((need) => (
              <li
                key={need}
                className="planned-module__need text-foreground flex items-start gap-2 text-sm"
              >
                <Check
                  className="text-muted-foreground mt-0.5 size-4 flex-none"
                  aria-hidden="true"
                />
                <span className="min-w-0 flex-1">{need}</span>
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      <div className="planned-module__actions flex flex-wrap items-center gap-2">
        <Link
          href={roadmap ? `${ROADMAP_PATH}#${roadmapModuleAnchor(roadmap.code)}` : ROADMAP_PATH}
          className={cn(buttonVariants({ variant: "outline" }))}
          title="See where this module sits in the product roadmap"
          aria-label="View in roadmap"
          data-testid="planned-module__roadmap-link"
        >
          <MapIcon aria-hidden="true" />
          View in roadmap
        </Link>
        <Link
          href={Routes.BACKOFFICE}
          className={cn(buttonVariants({ variant: "ghost" }))}
          title="Back to the dashboard"
          aria-label="Back to dashboard"
          data-testid="planned-module__dashboard-link"
        >
          <ArrowLeft aria-hidden="true" />
          Back to dashboard
        </Link>
      </div>
    </div>
  );
}
