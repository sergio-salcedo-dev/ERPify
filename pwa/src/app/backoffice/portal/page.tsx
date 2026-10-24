import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Client Portal",
};

export default function PortalPage() {
  return <PlannedModulePage module={PlannedModules.CLIENT_PORTAL} />;
}
