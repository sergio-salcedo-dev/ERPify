import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Stock Control",
};

export default function CatalogStockPage() {
  return <h1 className="text-foreground text-2xl font-semibold tracking-tight">Stock Control</h1>;
}
