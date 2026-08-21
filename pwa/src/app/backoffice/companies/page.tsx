import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Companies",
};

export default function CompaniesPage() {
  return <h1 className="text-foreground text-2xl font-semibold tracking-tight">Companies</h1>;
}
