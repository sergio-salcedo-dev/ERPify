"use client";

import { ResourceViewToggle, type ResourceView } from "@/components/erpify";

export type BanksView = ResourceView;

interface BanksViewToggleProps {
  view: BanksView;
  onViewChange: (next: BanksView) => void;
}

export function BanksViewToggle({ view, onViewChange }: Readonly<BanksViewToggleProps>) {
  return (
    <ResourceViewToggle
      view={view}
      onViewChange={onViewChange}
      ariaLabel="Banks view"
      testIdPrefix="banks-list"
    />
  );
}
