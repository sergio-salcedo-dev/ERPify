import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Pavement Configurator",
};

export default function ConfiguratorPage() {
  return <PlannedModulePage module={PlannedModules.CONFIGURATOR} />;
}
