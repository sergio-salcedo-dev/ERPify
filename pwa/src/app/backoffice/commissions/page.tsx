import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Commissions",
};

export default function CommissionsPage() {
  return <PlannedModulePage module={PlannedModules.COMMISSIONS} />;
}
