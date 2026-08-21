import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Departments",
};

export default function CatalogDepartmentsPage() {
  return <h1 className="text-foreground text-2xl font-semibold tracking-tight">Departments</h1>;
}
