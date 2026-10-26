"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { statusDotVariants } from "@/components/erpify/StatusBadge";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import {
  clearRecoveryRedeemed,
  peekRecoveryRedeemed,
} from "@/context/backoffice/user/application/recoveryRedeemedNotice";

const LINK_CLASS = "text-foreground font-medium underline underline-offset-4";

/**
 * The second half of "evict first, rotate second", shown once on the first dashboard visit after a
 * recovery secret was redeemed. The redemption already signed every other session out; it changed
 * no password, so whoever knows the old one can still sign in — the copy states the next step and
 * never that the account is safe.
 *
 * Its own named `<aside>` rather than a `NoticeBanner`, because it needs two links: changing the
 * password from the profile requires the current one, so a user who no longer knows it is sent to
 * Forgot password, which opens while signed in; completing it signs this session out too, since a
 * reset revokes every session, so the user signs back in with the new password. Deliberately not a
 * live region — the toast already announced the sign-in.
 *
 * Mounted by the page rather than the layout: the layout survives navigation, so the notice would
 * stand there until a reload instead of showing once.
 */
export function RecoveryRedeemedNextStep() {
  const [visible] = useState(peekRecoveryRedeemed);

  useEffect(() => {
    clearRecoveryRedeemed();
  }, []);

  if (!visible) {
    return null;
  }

  return (
    <aside
      aria-label="Next steps after recovery"
      className="dashboard__recovery-next-step border-border bg-card text-foreground mt-4 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border px-4 py-2.5 text-sm"
      data-testid="dashboard__recovery-next-step"
    >
      <span className={statusDotVariants({ variant: "info" })} aria-hidden="true" />
      <p className="dashboard__recovery-next-step-text min-w-0 flex-1">
        Next: set a new password — use{" "}
        <Link
          href={safeHref(Routes.FORGOT_PASSWORD)}
          className={LINK_CLASS}
          data-testid="dashboard__recovery-next-step-forgot-link"
        >
          Forgot password
        </Link>{" "}
        if you no longer know it — then create a new recovery secret. The one you used is spent.
      </p>
      <Link
        href={safeHref(Routes.PROFILE)}
        className={LINK_CLASS}
        data-testid="dashboard__recovery-next-step-link"
      >
        Open your profile
      </Link>
    </aside>
  );
}
