import React, { useState, useEffect } from "react";
import { LucideIcon, ChevronRight, ChevronDown } from "lucide-react";

interface SubItem {
  name: string;
  path: string;
  icon?: LucideIcon;
}

interface SidebarItemProps {
  name: string;
  icon: LucideIcon;
  path: string;
  isActive: boolean;
  onClick: (path: string) => void;
  subItems?: SubItem[];
  isCompact?: boolean;
}

export const SidebarItem: React.FC<SidebarItemProps> = ({
  name,
  icon: Icon,
  path,
  isActive,
  onClick,
  subItems,
  isCompact = false,
}) => {
  const [isOpen, setIsOpen] = useState(false);

  const hasSubItems = subItems && subItems.length > 0;

  // Auto-open if a sub-item is active
  useEffect(() => {
    if (isActive && hasSubItems) {
      setIsOpen(true);
    }
  }, [isActive, hasSubItems]);

  const handleItemClick = () => {
    if (!hasSubItems) {
      onClick(path);
    } else {
      if (!isCompact) {
        setIsOpen(!isOpen);
      } else {
        // In compact mode, clicking might just navigate to the first sub-item or the main path
        onClick(path);
      }
    }
  };

  return (
    <div className="sidebar-item-wrapper">
      <button
        onClick={handleItemClick}
        title={name}
        className={`sidebar-item w-full flex items-center justify-between p-2.5 rounded-xl font-semibold transition-all group ${
          isActive
            ? "sidebar-item--active bg-blue-50 text-blue-600 shadow-sm"
            : "text-slate-500 hover:bg-slate-50 hover:text-slate-900"
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
            const isSubActive = window.location.pathname === subItem.path;
            const SubIcon = subItem.icon;
            return (
              <button
                key={subItem.name}
                onClick={() => onClick(subItem.path)}
                title={subItem.name}
                className={`sidebar-item__sub-item w-full flex items-center gap-2.5 p-2 rounded-lg text-xs font-medium transition-all ${
                  isSubActive
                    ? "text-blue-600 bg-blue-50/50"
                    : "text-slate-500 hover:bg-slate-50 hover:text-slate-900"
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
};
