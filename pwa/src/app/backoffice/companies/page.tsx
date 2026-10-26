import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Companies",
};

export default function CompaniesPage() {
  return <PlannedModulePage module={PlannedModules.COMPANIES} />;
}
