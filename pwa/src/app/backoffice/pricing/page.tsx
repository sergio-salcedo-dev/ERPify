import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Price Lists",
};

export default function PricingPage() {
  return <h1 className="text-foreground text-2xl font-semibold tracking-tight">Price Lists</h1>;
}
