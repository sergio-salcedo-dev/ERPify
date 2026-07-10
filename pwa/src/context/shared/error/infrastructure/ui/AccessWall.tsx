"use client";

import { useEffect, useRef } from "react";
import Link from "next/link";
import { CircleSlash, PauseCircle, type LucideIcon } from "lucide-react";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/components/cn";

export const AccessWallVariant = {
  SUSPENDED: "suspended",
  DEACTIVATED: "deactivated",
} as const;
export type AccessWallVariant = (typeof AccessWallVariant)[keyof typeof AccessWallVariant];

interface AccessWallCopy {
  icon: LucideIcon;
  /** Visible non-color channel for the state, paired with the icon. */
  status: string;
  title: string;
  description: string;
}

// Neutral, non-enumerating copy: a wall is not an error, so the tone stays muted
// and the description never lists policy reasons.
const COPY: Record<AccessWallVariant, AccessWallCopy> = {
  [AccessWallVariant.SUSPENDED]: {
    icon: PauseCircle,
    status: "Suspended",
    title: "Access suspended",
    description:
      "Your access is currently suspended. Please contact your administrator to restore it.",
  },
  [AccessWallVariant.DEACTIVATED]: {
    icon: CircleSlash,
    status: "Deactivated",
    title: "Access deactivated",
    description:
      "This account is no longer active. Please contact your administrator if you need access restored.",
  },
};

/**
 * Card-content shown in place of the sign-in form when the API answers 403 for a
 * suspended or deactivated account. It renders INSIDE the centered `AuthLayout`
 * card (not the full-screen `ErrorScreen`), so it carries no header/logo of its
 * own. The badge/icon tone is deliberately neutral (`bg-muted`), never a danger
 * tone — a wall reports account state, not an error.
 */
export function AccessWall({ variant }: Readonly<{ variant: AccessWallVariant }>) {
  const headingRef = useRef<HTMLHeadingElement>(null);
  useEffect(() => {
    headingRef.current?.focus();
  }, []);

  const { icon: Icon, status, title, description } = COPY[variant];

  return (
    <div className="access-wall space-y-4 text-center" data-testid={`access-wall--${variant}`}>
      <div className="access-wall__icon-wrap bg-muted mx-auto flex size-12 items-center justify-center rounded-full">
        <Icon className="access-wall__icon text-muted-foreground size-6" aria-hidden="true" />
      </div>

      <p className="access-wall__status text-muted-foreground text-xs font-medium tracking-wider uppercase">
        {status}
      </p>

      <h1
        ref={headingRef}
        tabIndex={-1}
        className="access-wall__title text-foreground text-xl font-semibold tracking-tight outline-none"
      >
        {title}
      </h1>

      <p className="access-wall__description text-muted-foreground text-sm leading-relaxed">
        {description}
      </p>

      <Link
        href={safeHref(Routes.LOGIN)}
        aria-label="Sign in"
        title="Return to the sign-in page"
        className={cn(buttonVariants({ variant: "default" }), "w-full")}
        data-testid={`access-wall__sign-in--${variant}`}
      >
        Sign in
      </Link>
    </div>
  );
}
