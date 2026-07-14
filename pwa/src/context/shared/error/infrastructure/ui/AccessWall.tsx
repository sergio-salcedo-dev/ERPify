"use client";

import { useEffect, useRef } from "react";
import Link from "next/link";
import { CircleSlash, Link2Off, LockKeyhole, PauseCircle, type LucideIcon } from "lucide-react";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/components/cn";

export const AccessWallVariant = {
  SUSPENDED: "suspended",
  DEACTIVATED: "deactivated",
  LOCKED: "locked",
  INVALID_LINK: "invalid-link",
  INVALID_RESET_LINK: "invalid-reset-link",
} as const;
export type AccessWallVariant = (typeof AccessWallVariant)[keyof typeof AccessWallVariant];

interface AccessWallAction {
  /**
   * BEM element segment for the control's `data-testid`; the variant is
   * appended at render (`access-wall__<testId>--<variant>`), so an action can
   * be shared across variants.
   */
  testId: string;
  href: string;
  /** Visible text; doubles as the short, static accessible name. */
  label: string;
  title: string;
}

interface AccessWallCopy {
  icon: LucideIcon;
  /** Visible non-color channel for the state, paired with the icon. */
  status: string;
  title: string;
  description: string;
  /** Ordered action stack: the first is the primary CTA, the rest secondary. */
  actions: readonly AccessWallAction[];
}

const SIGN_IN_ACTION: AccessWallAction = {
  testId: "sign-in",
  href: Routes.LOGIN,
  label: "Sign in",
  title: "Return to the sign-in page",
};

const RECOVER_ACTION: AccessWallAction = {
  testId: "recover",
  href: Routes.FORGOT_PASSWORD,
  label: "Recover access",
  title: "Recover access to your account",
};

// The invitation-accept surface is Spanish (design spine), so its wall carries
// its own Spanish action stack rather than reusing the English SIGN_IN_ACTION.
const SIGN_IN_ES_ACTION: AccessWallAction = {
  testId: "sign-in",
  href: Routes.LOGIN,
  label: "Iniciar sesión",
  title: "Volver a la página de inicio de sesión",
};

// No self-service "request invitation" route exists in this single-tenant,
// invitation-first system — a new invitation is minted by an administrator. The
// action points to the public landing (the entry point where a locked-out user
// finds how to get access); the wall body directs them to their administrator.
const REQUEST_INVITATION_ACTION: AccessWallAction = {
  testId: "request-invitation",
  href: Routes.HOME,
  label: "Solicitar nueva invitación",
  title: "Solicitar una nueva invitación",
};

const REQUEST_RESET_LINK_ACTION: AccessWallAction = {
  testId: "request-reset-link",
  href: Routes.FORGOT_PASSWORD,
  label: "Solicitar enlace nuevo",
  title: "Solicitar un nuevo enlace de restablecimiento",
};

// Neutral, non-enumerating copy: a wall is not an error, so the tone stays muted
// and the description never lists policy reasons.
const COPY: Record<AccessWallVariant, AccessWallCopy> = {
  [AccessWallVariant.SUSPENDED]: {
    icon: PauseCircle,
    status: "Suspended",
    title: "Access suspended",
    description:
      "Your access is currently suspended. Please contact your administrator to restore it.",
    actions: [SIGN_IN_ACTION],
  },
  [AccessWallVariant.DEACTIVATED]: {
    icon: CircleSlash,
    status: "Deactivated",
    title: "Access deactivated",
    description:
      "This account is no longer active. Please contact your administrator if you need access restored.",
    actions: [SIGN_IN_ACTION],
  },
  [AccessWallVariant.LOCKED]: {
    icon: LockKeyhole,
    status: "Locked",
    title: "Access temporarily locked",
    description:
      "Access to this account is temporarily locked. Recover your access or try signing in again in a few minutes.",
    actions: [RECOVER_ACTION, SIGN_IN_ACTION],
  },
  [AccessWallVariant.INVALID_LINK]: {
    icon: Link2Off,
    status: "Enlace no válido",
    title: "Este enlace ya no es válido",
    description: "Solicita una nueva invitación a tu administrador para continuar.",
    actions: [SIGN_IN_ES_ACTION, REQUEST_INVITATION_ACTION],
  },
  // Same icon/status/title as INVALID_LINK on purpose: the opacity contract protects WHY the link
  // died (used/expired/unknown are one wall), not WHICH flow it belongs to — the URL already names
  // the flow. Only the exit differs: a reset link is self-service, an invitation is not.
  [AccessWallVariant.INVALID_RESET_LINK]: {
    icon: Link2Off,
    status: "Enlace no válido",
    title: "Este enlace ya no es válido",
    description: "Puedes solicitar un enlace nuevo desde «¿Has olvidado tu contraseña?».",
    actions: [SIGN_IN_ES_ACTION, REQUEST_RESET_LINK_ACTION],
  },
};

/**
 * Card-content shown in place of the sign-in form when the API answers 403 for a
 * suspended, deactivated, or locked account. It renders INSIDE the centered
 * `AuthLayout` card (not the full-screen `ErrorScreen`), so it carries no
 * header/logo of its own. The badge/icon tone is deliberately neutral
 * (`bg-muted`), never a danger tone — a wall reports account state, not an
 * error. Each variant declares its own action stack: the first action is the
 * filled primary CTA, any others render as secondary (outline) — the `locked`
 * wall pairs "Recover access" with "Sign in".
 */
export function AccessWall({ variant }: Readonly<{ variant: AccessWallVariant }>) {
  const headingRef = useRef<HTMLHeadingElement>(null);
  useEffect(() => {
    headingRef.current?.focus();
  }, []);

  const { icon: Icon, status, title, description, actions } = COPY[variant];

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

      <div className="access-wall__actions space-y-2">
        {actions.map((action, index) => (
          <Link
            key={action.testId}
            href={safeHref(action.href)}
            aria-label={action.label}
            title={action.title}
            className={cn(
              buttonVariants({ variant: index === 0 ? "default" : "outline" }),
              "w-full",
            )}
            data-testid={`access-wall__${action.testId}--${variant}`}
          >
            {action.label}
          </Link>
        ))}
      </div>
    </div>
  );
}
