import type { ReactNode } from "react";
import { type LucideIcon } from "lucide-react";
import { IconTone } from "@/context/shared/error/domain/IconTone";
import { Logo } from "@/context/shared/infrastructure/ui/components/atoms/Logo";
import { cn } from "@/lib/utils";

const iconToneClasses: Record<IconTone, { wrap: string; icon: string }> = {
  [IconTone.PRIMARY]: { wrap: "bg-primary/10", icon: "text-primary" },
  [IconTone.DESTRUCTIVE]: { wrap: "bg-destructive/10", icon: "text-destructive" },
  [IconTone.WARNING]: { wrap: "bg-warning/10", icon: "text-warning" },
  [IconTone.MUTED]: { wrap: "bg-muted", icon: "text-muted-foreground" },
};

export interface ErrorScreenProps {
  /** Unique BEM / `data-testid` prefix for this screen (e.g. `not-found`, `error-page`). */
  testIdPrefix: string;
  /** Short status line above the title (e.g. `Error 404`, `No connection`). */
  status: string;
  /** Main heading. */
  title: string;
  /** Single-paragraph description. */
  description: string;
  /** Lucide icon for the circular badge. */
  icon: LucideIcon;
  /** Tone of the icon badge. */
  iconTone?: IconTone;
  /** Optional content between description and actions — debug details, digest, … */
  extras?: ReactNode;
  /** Action row (1–2 buttons or links). */
  actions: ReactNode;
  /** Use `alert` for genuine error boundaries; omit for static info pages. */
  mainRole?: "alert";
  /** Branded header bar. Disable only for `global-error.tsx` (renders its own `<html>`/`<body>`). */
  withHeader?: boolean;
}

/**
 * Responsive, design-system-aligned error screen used by every Next.js error
 * file under `src/app/`: `not-found.tsx`, `error.tsx`, `global-error.tsx`,
 * the `forbidden`/`unauthorized` Next 15+ convention files, and the bespoke
 * `/maintenance`, `/rate-limited`, `/offline`, `/unauthorized`,
 * `/unauthenticated` routes.
 *
 * Centralising the shell here means a single place to tune the breakpoints
 * (mobile / tablet / laptop / desktop / large) and the visual hierarchy of
 * the icon badge, the status label, the title, the description and the
 * action row — across every error surface at once.
 *
 * It deliberately has no `"use client"` directive: it's a pure render with
 * no runtime context, so it composes inside server pages AND inside the
 * `error.tsx` / `global-error.tsx` client boundaries.
 */
export function ErrorScreen({
  testIdPrefix,
  status,
  title,
  description,
  icon: Icon,
  iconTone = IconTone.PRIMARY,
  extras,
  actions,
  mainRole,
  withHeader = true,
}: ErrorScreenProps) {
  const tone = iconToneClasses[iconTone];

  return (
    <div
      className="error-screen bg-background flex min-h-screen flex-col"
      data-testid={testIdPrefix}
    >
      {withHeader ? (
        <header className="error-screen__header border-border bg-card border-b">
          <div className="error-screen__header-inner mx-auto flex items-center px-4 py-3 sm:px-6 sm:py-4 lg:px-8">
            <Logo
              variant="badge"
              size="md"
              className="error-screen__logo"
              iconClassName="error-screen__logo-icon"
              textClassName="error-screen__logo-text"
            />
          </div>
        </header>
      ) : null}

      <main
        role={mainRole}
        aria-live={mainRole === "alert" ? "assertive" : undefined}
        className="error-screen__main flex flex-1 items-center justify-center px-4 py-8 sm:px-6 sm:py-12 lg:px-8 lg:py-20 xl:py-24"
      >
        <section
          className="error-screen__panel bg-card border-border w-full max-w-md rounded-xl border p-6 text-center shadow-sm sm:max-w-xl sm:rounded-lg sm:p-10 lg:max-w-2xl lg:p-12 xl:max-w-3xl xl:p-16"
          data-testid={`${testIdPrefix}__panel`}
        >
          <div
            className={cn(
              "error-screen__icon-wrap mx-auto flex size-14 items-center justify-center rounded-full sm:size-16 lg:size-20",
              tone.wrap,
            )}
          >
            <Icon
              className={cn("error-screen__icon size-7 sm:size-8 lg:size-10", tone.icon)}
              aria-hidden="true"
            />
          </div>

          <p
            className="error-screen__status text-muted-foreground mt-5 text-xs font-medium tracking-wider uppercase sm:mt-6 sm:text-sm"
            data-testid={`${testIdPrefix}__status`}
          >
            {status}
          </p>

          <h1
            className="error-screen__title text-foreground mt-2 text-2xl font-semibold tracking-tight sm:mt-3 sm:text-3xl md:text-4xl lg:text-5xl"
            data-testid={`${testIdPrefix}__title`}
          >
            {title}
          </h1>

          <p
            className="error-screen__description text-muted-foreground mx-auto mt-3 max-w-md text-sm leading-relaxed sm:mt-4 sm:text-base lg:max-w-xl lg:text-lg"
            data-testid={`${testIdPrefix}__description`}
          >
            {description}
          </p>

          {extras ? <div className="error-screen__extras mt-5 sm:mt-6">{extras}</div> : null}

          <div className="error-screen__actions mt-6 flex flex-col items-stretch justify-center gap-2 sm:mt-8 sm:flex-row sm:items-center sm:gap-3">
            {actions}
          </div>
        </section>
      </main>
    </div>
  );
}
