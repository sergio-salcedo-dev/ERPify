import type { Metadata } from "next";
import type { ReactNode } from "react";

export const metadata: Metadata = {
  title: "System Status",
};

export default function StatusLayout({ children }: Readonly<{ children: ReactNode }>) {
  return children;
}
