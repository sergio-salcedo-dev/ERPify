import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Systems Catalog",
};

export default function PavementSystemsPage() {
  return <h1 className="text-foreground text-2xl font-semibold tracking-tight">Systems Catalog</h1>;
}
