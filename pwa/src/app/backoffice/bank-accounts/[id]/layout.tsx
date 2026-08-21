import type { Metadata } from "next";

// This segment's page is a Client Component, and `metadata` is server-only. The layout is the
// seam that keeps the title declarative — see `tests/backoffice-route-titles.test.ts` for why a
// route without one arrives unnamed for assistive technology.
export const metadata: Metadata = {
  title: "Bank account detail",
};

export default function BankAccountDetailLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return children;
}
