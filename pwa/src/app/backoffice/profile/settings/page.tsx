import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Profile settings",
  robots: { index: false, follow: false },
};

export default function SettingsPage() {
  return <PlannedModulePage module={PlannedModules.PROFILE_SETTINGS} />;
}
