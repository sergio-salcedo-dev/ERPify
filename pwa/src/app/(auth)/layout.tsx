import type { Metadata } from "next";
import type { ReactNode } from "react";
import { Logo } from "@/components/erpify";
import { Routes } from "@/context/shared/domain/types/routes";

export const metadata: Metadata = {
  robots: { index: false },
};

/** Centered card shell for the public auth pages. No backoffice chrome. */
export default function AuthLayout({ children }: Readonly<{ children: ReactNode }>) {
  return (
    <div className="bg-background flex min-h-dvh items-center justify-center px-4 py-10">
      <div className="w-full max-w-sm space-y-6">
        <div className="flex justify-center">
          <Logo href={Routes.HOME} size="lg" />
        </div>
        <div className="border-border bg-card rounded-lg border p-6 shadow-sm">{children}</div>
      </div>
    </div>
  );
}
