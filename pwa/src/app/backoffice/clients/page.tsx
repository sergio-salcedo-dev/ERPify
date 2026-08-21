import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Clients",
};

export default function ClientsPage() {
  return <h1 className="text-foreground text-2xl font-semibold tracking-tight">Clients</h1>;
}
