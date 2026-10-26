import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Systems Catalog",
};

export default function PavementSystemsPage() {
  return <PlannedModulePage module={PlannedModules.PAVEMENT_SYSTEMS} />;
}
