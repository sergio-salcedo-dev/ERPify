import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Quotes",
};

export default function QuotesPage() {
  return <h1 className="text-foreground text-2xl font-semibold tracking-tight">Quotes</h1>;
}
