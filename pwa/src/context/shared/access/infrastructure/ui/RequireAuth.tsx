"use client";

import { useEffect, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import { useSession } from "../../application/useSession";
import { Routes } from "@/context/shared/domain/types/routes";

/**
 * Route protection. Identity is resolved before authorization: while the
 * provider is `hydrating` it renders nothing and does not redirect (the stored
 * session has not been read yet). Once resolved, only an `authenticated` (ACTIVE)
 * session sees the children; anything else is redirected to /login. Protected
 * content therefore never flashes on the strength of a default session.
 */
export function RequireAuth({ children }: Readonly<{ children: ReactNode }>) {
  const router = useRouter();
  const { status } = useSession();

  useEffect(() => {
    if (status === "unauthenticated") router.replace(Routes.LOGIN);
  }, [status, router]);

  if (status !== "authenticated") return null;
  return <>{children}</>;
}
