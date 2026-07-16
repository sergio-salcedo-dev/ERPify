"use client";

import { useEffect, useRef } from "react";
import Link from "next/link";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/components/cn";

const DEFAULT_SIGNAL_TITLE = "Invitation accepted. You're ready to get started.";
const ENTER_LABEL = "Enter";
const ENTER_TITLE = "Enter the dashboard";

/**
 * Success card shown in place of a token-action form once a credential-setting
 * action succeeds and the session cookie is already set — invitation-accept and
 * password-reset both land here, differing only in the {@link title} they pass.
 * It renders INSIDE the centered `AuthLayout` card, so it carries no header/logo
 * of its own. The success is signalled by a static (non-animated) success dot
 * AND the title text — never color alone. Focus moves to the single `<h1>` on
 * the SPA transition, and the primary action is a static in-app link into the
 * ERP (`Routes.BACKOFFICE`), never a redirect target read from the URL or a
 * payload.
 */
export function SecuritySignal({
  testId = "security-signal",
  title = DEFAULT_SIGNAL_TITLE,
}: Readonly<{ testId?: string; title?: string }>) {
  const headingRef = useRef<HTMLHeadingElement>(null);
  useEffect(() => {
    headingRef.current?.focus();
  }, []);

  return (
    <div className="security-signal space-y-4 text-center" data-testid={testId}>
      <div className="security-signal__icon-wrap bg-success/10 mx-auto flex size-12 items-center justify-center rounded-full">
        <span className="security-signal__dot bg-success size-3 rounded-full" aria-hidden="true" />
      </div>

      <h1
        ref={headingRef}
        tabIndex={-1}
        className="security-signal__title text-foreground text-xl font-semibold tracking-tight outline-none"
      >
        {title}
      </h1>

      <div className="security-signal__actions">
        <Link
          href={safeHref(Routes.BACKOFFICE)}
          aria-label={ENTER_LABEL}
          title={ENTER_TITLE}
          className={cn(buttonVariants({ variant: "default" }), "w-full")}
          data-testid={`${testId}__enter`}
        >
          {ENTER_LABEL}
        </Link>
      </div>
    </div>
  );
}
