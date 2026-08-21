import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Global Transactions",
};

export default function FinanceTransactionsPage() {
  return (
    <h1 className="text-foreground text-2xl font-semibold tracking-tight">Global Transactions</h1>
  );
}
