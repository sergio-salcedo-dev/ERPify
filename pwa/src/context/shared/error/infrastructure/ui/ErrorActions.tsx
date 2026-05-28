"use client";

import { useRouter, usePathname } from "next/navigation";
import Link from "next/link";
import { ChevronLeft, Home, LayoutDashboard } from "lucide-react";
import { buttonVariants } from "@/components/ui/button-variants";
import { Routes } from "@/context/shared/domain/types/routes";
import { cn } from "@/lib/utils";

/**
 * Shared sizing for every action button in the error module. Exported so the
 * Next.js error boundaries (`app/error.tsx`, `app/global-error.tsx`) can size
 * their bespoke "Try again" reset button identically to the row this
 * component renders.
 */
export const ERROR_ACTION_BTN_CLASSES =
  "h-11 w-full px-6 text-base sm:w-auto sm:h-10 lg:h-11 lg:text-base";

interface ErrorActionsProps {
  /**
   * Visual variant for the primary navigation button. Defaults to
   * `"default"` (filled) for static error pages where no other CTA is
   * present. The Next.js error boundaries pass `"outline"` so their
   * `Try again` button can take the single filled-primary slot without
   * competing visually with the navigation links.
   */
  primaryVariant?: "default" | "outline";
}

/**
 * Standard action row for every error / fallback screen in the PWA. Renders
 * exactly two buttons:
 *
 *  1. **Primary navigation** — `Return to BackOffice` when the failed URL
 *     was under {@link Routes.BACKOFFICE}, otherwise `Return home`.
 *  2. **Secondary navigation** — `Go back`, which calls `router.back()` when
 *     there is browser history to walk and otherwise falls back to the
 *     primary destination so the user is never stranded.
 *
 * It is a Client Component on purpose: `usePathname()` and `router.back()`
 * are only meaningful on the client, and that's also why detection happens
 * here instead of being passed in as a prop — the surrounding error page
 * (server- or client-rendered) does not need to know which prefix the
 * request hit.
 */
export function ErrorActions({ primaryVariant = "default" }: ErrorActionsProps = {}) {
  const router = useRouter();
  const pathname = usePathname();
  const inBackoffice = pathname?.startsWith(Routes.BACKOFFICE) ?? false;

  const primaryHref = inBackoffice ? Routes.BACKOFFICE : Routes.HOME;
  const primaryClasses = cn(
    buttonVariants({ variant: primaryVariant, size: "lg" }),
    ERROR_ACTION_BTN_CLASSES,
    "error-actions__primary-link",
  );

  function handleBack(): void {
    if (globalThis.window !== undefined && globalThis.window.history.length > 1) {
      router.back();
      return;
    }
    router.push(primaryHref);
  }

  return (
    <>
      {inBackoffice ? (
        <Link
          href={Routes.BACKOFFICE}
          className={primaryClasses}
          data-icon="inline-start"
          title="Return to BackOffice"
          aria-label="BackOffice"
          data-testid="error-actions__backoffice-link"
        >
          <LayoutDashboard className="size-4" aria-hidden="true" />
          Return to BackOffice
        </Link>
      ) : (
        <Link
          href={Routes.HOME}
          className={primaryClasses}
          data-icon="inline-start"
          title="Return to home"
          aria-label="Home"
          data-testid="error-actions__home-link"
        >
          <Home className="size-4" aria-hidden="true" />
          Return home
        </Link>
      )}
      <button
        type="button"
        onClick={handleBack}
        className={cn(
          buttonVariants({ variant: "outline", size: "lg" }),
          ERROR_ACTION_BTN_CLASSES,
          "error-actions__back-button",
        )}
        data-icon="inline-start"
        title="Go back to the previous page"
        aria-label="Go back"
        data-testid="error-actions__back-button"
      >
        <ChevronLeft className="size-4" aria-hidden="true" />
        Go back
      </button>
    </>
  );
}
