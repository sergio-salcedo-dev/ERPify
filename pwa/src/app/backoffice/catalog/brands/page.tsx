import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Brands & Manufacturers",
};

export default function CatalogBrandsPage() {
  return (
    <h1 className="text-foreground text-2xl font-semibold tracking-tight">
      Brands & Manufacturers
    </h1>
  );
}
