import Link from "next/link";
import { ArrowLeft, Wrench } from "lucide-react";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/lib/utils";
import { DEV_TOOL_GROUPS } from "./devToolGroups";

/**
 * Hub page rendered at `/backoffice/dev-tools` listing every dev / QA tool the PWA
 * ships. Hidden from production via the route's `notFound()` guard and
 * the `isDevToolsAvailable()` checks at every entry point (frontoffice
 * navbar, backoffice sidebar).
 *
 * The page is a pure server render — the registry is authoritative and
 * lives in `devToolGroups.ts`. Add a new tool there and it shows up here
 * automatically.
 */
export function DevToolsMenu() {
  return (
    <div className="dev-tools" data-testid="dev-tools">
      <div className="mx-auto max-w-5xl py-4 sm:py-8">
        <header className="dev-tools__header border-warning/40 bg-warning/10 mb-8 rounded-md border p-4 sm:p-5">
          <div className="dev-tools__header-row flex items-center gap-2">
            <Wrench className="text-warning-strong size-4 sm:size-5" aria-hidden="true" />
            <p className="text-warning-strong text-xs font-semibold tracking-wider uppercase">
              Dev / test only
            </p>
          </div>
          <h1 className="text-foreground mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">
            Dev Tools
          </h1>
          <p className="text-muted-foreground mt-2 text-sm sm:text-base">
            Internal hub for QA and engineering. Each entry below opens a tool scoped to that area.
            The hub itself returns 404 in production builds, and the entry points in the frontoffice
            navbar / backoffice sidebar disappear as well.
          </p>
        </header>

        {DEV_TOOL_GROUPS.map((group) => (
          <section key={group.label} className="dev-tools__group mb-10">
            <h2
              className="dev-tools__group-label text-muted-foreground text-[11px] font-semibold tracking-widest uppercase sm:text-xs"
              data-testid={`dev-tools__group-${group.label}`}
            >
              {group.label}
            </h2>
            <ul className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {group.tools.map((tool) => (
                <li key={tool.url}>
                  <Link
                    href={tool.url}
                    className={cn(
                      buttonVariants({ variant: "outline", size: "lg" }),
                      "dev-tools__tool h-auto w-full flex-col items-start gap-2 px-4 py-4 text-left whitespace-normal",
                    )}
                    data-testid={`dev-tools__tool-${tool.url}`}
                  >
                    <div className="flex items-center gap-2">
                      <tool.icon className="text-primary size-4 shrink-0" aria-hidden="true" />
                      <span className="text-foreground text-sm font-semibold">{tool.label}</span>
                    </div>
                    <span className="text-muted-foreground text-xs leading-snug font-normal">
                      {tool.description}
                    </span>
                    <span className="text-muted-foreground/80 mt-auto font-mono text-[11px]">
                      {tool.url}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          </section>
        ))}

        <footer className="dev-tools__footer border-border mt-12 flex flex-col gap-3 border-t pt-6 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-muted-foreground text-xs sm:text-sm">
            Jump back to a real surface when you&apos;re done:
          </p>
          <div className="dev-tools__footer-nav flex flex-col gap-2 sm:flex-row sm:gap-3">
            <Link
              href={Routes.HOME}
              className={cn(
                buttonVariants({ variant: "outline", size: "sm" }),
                "dev-tools__footer-link",
              )}
              data-icon="inline-start"
              data-testid="dev-tools__footer-frontoffice"
              title="Go to FrontOffice (landing)"
              aria-label="FrontOffice"
            >
              <ArrowLeft className="size-3.5" aria-hidden="true" />
              FrontOffice
            </Link>
          </div>
        </footer>
      </div>
    </div>
  );
}
