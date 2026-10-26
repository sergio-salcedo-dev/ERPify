import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Project Costs",
};

export default function FinanceAccountingPage() {
  return <PlannedModulePage module={PlannedModules.PROJECT_COSTS} />;
}
