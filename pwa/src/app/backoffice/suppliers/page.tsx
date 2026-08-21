import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Suppliers",
};

export default function SuppliersPage() {
  return <h1 className="text-foreground text-2xl font-semibold tracking-tight">Suppliers</h1>;
}
