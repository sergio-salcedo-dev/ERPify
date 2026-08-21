import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Pavement Configurator",
};

export default function ConfiguratorPage() {
  return (
    <h1 className="text-foreground text-2xl font-semibold tracking-tight">Pavement Configurator</h1>
  );
}
