"use client";

import { useEffect, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import { useSession } from "../../application/useSession";
import { UserStatus } from "../../domain/UserStatus";
import { Routes } from "@/context/shared/domain/types/routes";

/**
 * Route protection: no session or a non-ACTIVE user (e.g. BLOCKED via the dev
 * switcher) is redirected to /login. While redirecting it renders nothing so
 * protected content never flashes.
 */
export function RequireAuth({ children }: Readonly<{ children: ReactNode }>) {
  const router = useRouter();
  const { session } = useSession();
  const denied = !session || session.user.status !== UserStatus.ACTIVE;

  useEffect(() => {
    if (denied) router.replace(Routes.LOGIN);
  }, [denied, router]);

  if (denied) return null;
  return <>{children}</>;
}
