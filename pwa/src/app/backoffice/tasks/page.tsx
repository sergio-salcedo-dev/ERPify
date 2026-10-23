import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Tasks",
};

export default function TasksPage() {
  return <PlannedModulePage module={PlannedModules.TASKS} />;
}
