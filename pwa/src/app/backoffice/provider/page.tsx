import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Provider Portal",
};

export default function ProviderPage() {
  return <PlannedModulePage module={PlannedModules.PROVIDER_PORTAL} />;
}
