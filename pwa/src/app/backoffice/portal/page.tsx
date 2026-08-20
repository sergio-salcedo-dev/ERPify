import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Client Portal",
};

export default function PortalPage() {
  return <h1 className="text-foreground text-2xl font-semibold tracking-tight">Client Portal</h1>;
}
