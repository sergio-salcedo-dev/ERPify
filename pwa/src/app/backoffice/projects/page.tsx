import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Projects",
};

export default function ProjectsPage() {
  return <PlannedModulePage module={PlannedModules.PROJECTS} />;
}
