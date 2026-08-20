import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Cash Flow",
};

export default function FinanceCashFlowPage() {
  return <h1 className="text-foreground text-2xl font-semibold tracking-tight">Cash Flow</h1>;
}
