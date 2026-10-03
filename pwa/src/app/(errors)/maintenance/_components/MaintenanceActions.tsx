"use client";

import { useEffect, useSyncExternalStore } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { ArrowRight, LogIn } from "lucide-react";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/components/cn";
import { ERROR_ACTION_BTN_CLASSES, ErrorActions } from "@/context/shared/error/infrastructure/ui";
import { useSession } from "@/context/shared/access/application/useSession";
import { AuthStatus } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { useDeparture } from "@/context/shared/navigation/application/useDeparture";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { safeInternalPath } from "@/context/shared/navigation/domain/safeInternalPath";
import { Routes } from "@/context/shared/routing/domain/Routes";

function subscribeToNothing(): () => void {
  return () => undefined;
}

function readNext(): string | null {
  return new URLSearchParams(globalThis.location.search).get("next");
}

function noNextOnTheServer(): null {
  return null;
}

/**
 * Where a visitor the guard parked here goes once the session status is decided again, or `null`
 * while it is not (or when nobody parked them: no `next`). `next` arrives in the URL, so it is
 * untrusted and passes the same open-redirect guard the sign-in form applies to its own `next`.
 */
export function maintenanceWayBack(status: AuthStatus, next: string | null): string | null {
  if (!next) return null;
  const target = safeInternalPath(next, Routes.BACKOFFICE);
  if (status === AuthStatus.AUTHENTICATED) return target;
  if (status === AuthStatus.UNAUTHENTICATED) {
    return `${Routes.LOGIN}?next=${encodeURIComponent(target)}`;
  }
  return null;
}

/**
 * The maintenance page's action row. A visit the guard sent here (it carries `?next=`) is waiting
 * for the server to answer `/me` again, which the session provider re-probes on its own; once it
 * does, this returns the visitor to the blocked page — through the sign-in form when there is no
 * live session — and also offers that way back as a link in case the navigation does not happen.
 * Any other visit (no `next`) gets the standard error actions only, so a direct or gallery visit
 * is never moved.
 */
export function MaintenanceActions() {
  const router = useRouter();
  const { status } = useSession();
  const departing = useDeparture() !== null;
  // The query is read from the live location rather than through `useSearchParams`, which would
  // need a Suspense boundary around a page that is otherwise static.
  const next = useSyncExternalStore(subscribeToNothing, readNext, noNextOnTheServer);
  const wayBack = maintenanceWayBack(status, next);

  useEffect(() => {
    if (wayBack === null || departing) return;
    router.replace(safeHref(wayBack, Routes.BACKOFFICE));
  }, [wayBack, departing, router]);

  if (wayBack === null) return <ErrorActions />;

  const signIn = status === AuthStatus.UNAUTHENTICATED;
  return (
    <>
      <Link
        href={safeHref(wayBack, Routes.BACKOFFICE)}
        className={cn(
          buttonVariants({ variant: "default", size: "lg" }),
          ERROR_ACTION_BTN_CLASSES,
          "maintenance__way-back-link",
        )}
        data-icon="inline-start"
        title={signIn ? "Sign in to continue" : "Continue where you left off"}
        aria-label={signIn ? "Sign in" : "Continue"}
        data-testid="maintenance__way-back-link"
      >
        {signIn ? (
          <LogIn className="size-4" aria-hidden="true" />
        ) : (
          <ArrowRight className="size-4" aria-hidden="true" />
        )}
        {signIn ? "Sign in" : "Continue"}
      </Link>
      <ErrorActions primaryVariant="outline" />
    </>
  );
}
