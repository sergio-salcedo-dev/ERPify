import type { Metadata } from "next";
import type { ReactNode } from "react";
import BackOfficeLayoutClient from "./BackOfficeLayoutClient";

export const metadata: Metadata = {
  robots: {
    index: false,
    follow: false,
  },
};

export default function BackOfficeLayout({ children }: { children: ReactNode }) {
  return <BackOfficeLayoutClient>{children}</BackOfficeLayoutClient>;
}
