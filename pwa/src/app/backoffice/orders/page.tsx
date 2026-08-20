import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Sales Orders",
};

export default function OrdersPage() {
  return <h1 className="text-foreground text-2xl font-semibold tracking-tight">Sales Orders</h1>;
}
