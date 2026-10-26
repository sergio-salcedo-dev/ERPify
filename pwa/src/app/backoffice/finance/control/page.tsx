import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Budget vs Actuals",
};

export default function FinanceControlPage() {
  return <PlannedModulePage module={PlannedModules.BUDGET_VS_ACTUALS} />;
}
