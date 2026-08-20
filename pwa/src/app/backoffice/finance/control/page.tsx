import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Budget vs Actuals",
};

export default function FinanceControlPage() {
  return (
    <h1 className="text-foreground text-2xl font-semibold tracking-tight">Budget vs Actuals</h1>
  );
}
