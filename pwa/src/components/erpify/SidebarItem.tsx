"use client";

import { useState } from "react";
import { usePathname } from "next/navigation";
import { LucideIcon, ChevronRight, ChevronDown } from "lucide-react";

interface SubItem {
  name: string;
  path: string;
  icon?: LucideIcon;
  /** Optional `data-testid` for the sub-item button. */
  testId?: string;
}

interface SidebarItemProps {
  name: string;
  icon: LucideIcon;
  path: string;
  isActive: boolean;
  onClick: (path: string) => void;
  subItems?: SubItem[];
  isCompact?: boolean;
  /** Optional `data-testid` for the top-level item button. */
  testId?: string;
}

export function SidebarItem({
  name,
  icon: Icon,
  path,
  isActive,
  onClick,
  subItems,
  isCompact = false,
  testId,
}: Readonly<SidebarItemProps>) {
  const [isOpen, setIsOpen] = useState(false);
  const pathname = usePathname();

  const hasSubItems = subItems && subItems.length > 0;

  // Auto-open if a sub-item is active (adjusting state during render to avoid cascading renders)
  const [prevIsActive, setPrevIsActive] = useState(isActive);
  if (isActive !== prevIsActive) {
    setPrevIsActive(isActive);
    if (isActive && hasSubItems) {
      setIsOpen(true);
    }
  }

  const handleItemClick = () => {
    if (hasSubItems && !isCompact) {
      setIsOpen(!isOpen);
      return;
    }
    // No sub-items, or compact mode collapses the chevron interaction — fall
    // back to navigating to the main path.
    onClick(path);
  };

  return (
    <div className="sidebar-item-wrapper">
      <button
        type="button"
        onClick={handleItemClick}
        title={name}
        data-testid={testId}
        className={`sidebar-item w-full flex items-center justify-between p-2.5 rounded-md font-semibold transition-all group ${
          isActive
            ? "sidebar-item--active bg-primary/15 text-primary shadow-sm"
            : "text-muted-foreground hover:bg-accent hover:text-foreground"
        } ${isCompact ? "justify-center" : ""}`}
      >
        <div
          className={`sidebar-item__content flex items-center ${isCompact ? "justify-center" : "gap-3"}`}
        >
          <Icon className="sidebar-item__icon w-5 h-5 shrink-0" />
          {!isCompact && <span className="sidebar-item__name text-sm truncate">{name}</span>}
        </div>
        {!isCompact && (
          <>
            {hasSubItems ? (
              <ChevronDown
                className={`sidebar-item__chevron w-3.5 h-3.5 transition-transform ${isOpen ? "rotate-180" : ""}`}
              />
            ) : (
              <ChevronRight
                className={`sidebar-item__chevron w-3.5 h-3.5 transition-transform ${isActive ? "translate-x-0.5" : "opacity-0 group-hover:opacity-100"}`}
              />
            )}
          </>
        )}
      </button>

      {hasSubItems && isOpen && !isCompact && (
        <div className="sidebar-item__sub-items ml-9 mt-1 space-y-1">
          {subItems.map((subItem) => {
            const isSubActive = pathname === subItem.path;
            const SubIcon = subItem.icon;
            return (
              <button
                type="button"
                key={subItem.name}
                onClick={() => onClick(subItem.path)}
                title={subItem.name}
                data-testid={subItem.testId}
                className={`sidebar-item__sub-item w-full flex items-center gap-2.5 p-2 rounded-md text-xs font-medium transition-all ${
                  isSubActive
                    ? "text-primary bg-primary/10"
                    : "text-muted-foreground hover:bg-accent hover:text-foreground"
                }`}
              >
                {SubIcon && <SubIcon className="w-3.5 h-3.5 shrink-0" />}
                <span className="truncate">{subItem.name}</span>
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}
