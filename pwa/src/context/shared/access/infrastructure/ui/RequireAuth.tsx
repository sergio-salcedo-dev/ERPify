"use client";

import { useEffect, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import { useSession } from "../../application/useSession";
import { AuthStatus } from "./AuthProvider";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { safeInternalPath } from "@/context/shared/navigation/domain/safeInternalPath";

/**
 * Route protection. Identity is resolved before authorization: while the
 * provider is `hydrating` it renders nothing and does not redirect (the stored
 * session has not been read yet). Once resolved, only an `authenticated` (ACTIVE)
 * session sees the children; anything else is redirected to /login with the
 * blocked target preserved in `?next=` so login can return there. Protected
 * content therefore never flashes on the strength of a default session.
 */
export function RequireAuth({ children }: Readonly<{ children: ReactNode }>) {
  const router = useRouter();
  const { status, isSigningOut } = useSession();

  useEffect(() => {
    if (status !== AuthStatus.UNAUTHENTICATED) return;
    // A sign-out already owns a navigation away from here; redirecting on top of it would
    // race two navigations against the same document. The interaction that started it clears
    // this once its own outcome is known, which re-runs this effect.
    if (isSigningOut) return;
    // Read the live location inside the effect (client-only) so the deep link is
    // preserved without pulling useSearchParams + a Suspense boundary into the
    // guard. safeInternalPath keeps a tampered target from becoming an open
    // redirect even though this one originates from our own routing.
    const target = safeInternalPath(
      `${globalThis.location.pathname}${globalThis.location.search}`,
      Routes.BACKOFFICE,
    );
    router.replace(`${Routes.LOGIN}?next=${encodeURIComponent(target)}`);
  }, [status, isSigningOut, router]);

  if (status !== AuthStatus.AUTHENTICATED) return null;
  return <>{children}</>;
}
