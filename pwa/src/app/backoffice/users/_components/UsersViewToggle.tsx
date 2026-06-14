"use client";

import { ResourceViewToggle, type ResourceView } from "@/components/erpify";

export type UsersView = ResourceView;

interface UsersViewToggleProps {
  view: UsersView;
  onViewChange: (next: UsersView) => void;
}

export function UsersViewToggle({ view, onViewChange }: Readonly<UsersViewToggleProps>) {
  return (
    <ResourceViewToggle
      view={view}
      onViewChange={onViewChange}
      ariaLabel="Users view"
      testIdPrefix="users-list"
    />
  );
}
