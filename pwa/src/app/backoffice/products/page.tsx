import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Products Catalog",
};

export default function ProductsPage() {
  return (
    <h1 className="text-foreground text-2xl font-semibold tracking-tight">Products Catalog</h1>
  );
}
