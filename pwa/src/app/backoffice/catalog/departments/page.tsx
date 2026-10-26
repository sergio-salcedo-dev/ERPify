import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Departments",
};

export default function CatalogDepartmentsPage() {
  return <PlannedModulePage module={PlannedModules.DEPARTMENTS} />;
}
