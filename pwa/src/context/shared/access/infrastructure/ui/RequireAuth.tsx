"use client";

import { useEffect, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import { useSession } from "../../application/useSession";
import { AuthStatus } from "./AuthProvider";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { safeInternalPath } from "@/context/shared/navigation/domain/safeInternalPath";
import { useDeparture } from "@/context/shared/navigation/application/useDeparture";

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
  const { status } = useSession();
  const departing = useDeparture() !== null;

  useEffect(() => {
    if (status !== AuthStatus.UNAUTHENTICATED) return;
    // A full-document departure already owns a navigation away from here; redirecting on top
    // of it would race two navigations against the same document. Whoever claimed it releases
    // once its own outcome is known, which re-runs this effect.
    //
    // The claim rather than a boolean on this context: the third reader of this same fact is
    // `FetchHttpClient`, a module-level function inside an infrastructure adapter that reaches
    // no provider, so a context value could never have served it. Reading the claim here is
    // what keeps the fact single-owned instead of mirrored in two places that must agree.
    if (departing) return;
    // Read the live location inside the effect (client-only) so the deep link is
    // preserved without pulling useSearchParams + a Suspense boundary into the
    // guard. safeInternalPath keeps a tampered target from becoming an open
    // redirect even though this one originates from our own routing.
    const target = safeInternalPath(
      `${globalThis.location.pathname}${globalThis.location.search}`,
      Routes.BACKOFFICE,
    );
    router.replace(`${Routes.LOGIN}?next=${encodeURIComponent(target)}`);
  }, [status, departing, router]);

  if (status !== AuthStatus.AUTHENTICATED) return null;
  return <>{children}</>;
}
