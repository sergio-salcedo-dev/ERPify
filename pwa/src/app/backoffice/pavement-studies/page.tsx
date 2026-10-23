import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Technical Studies",
};

export default function PavementStudiesPage() {
  return <PlannedModulePage module={PlannedModules.PAVEMENT_STUDIES} />;
}
