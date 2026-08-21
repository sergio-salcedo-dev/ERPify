import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Finance",
};

export default function FinancePage() {
  return <h1 className="text-foreground text-2xl font-semibold tracking-tight">Finance</h1>;
}
