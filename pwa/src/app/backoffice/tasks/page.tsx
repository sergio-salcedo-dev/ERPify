import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Team Tasks",
};

export default function TasksPage() {
  return <h1 className="text-foreground text-2xl font-semibold tracking-tight">Team Tasks</h1>;
}
