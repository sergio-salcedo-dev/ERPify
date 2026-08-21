import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Employees",
};

export default function CompaniesEmployeesPage() {
  return <h1 className="text-foreground text-2xl font-semibold tracking-tight">Employees</h1>;
}
