"use client";

import { useEffect, useState, type ReactNode } from "react";
import { PanelLeftClose, PanelLeftOpen } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

const SIDEBAR_STORAGE_KEY = "erpify:sidebar-open";

interface AppShellNavItem {
  href: string;
  label: string;
  icon?: ReactNode;
  active?: boolean;
}

interface AppShellProps {
  brand?: ReactNode;
  navigation: AppShellNavItem[];
  /** Right-aligned slot in the top bar (search, user menu, notifications). */
  topBarRight?: ReactNode;
  children: ReactNode;
  /** Initial sidebar state when no localStorage value is present. Defaults to true. */
  defaultSidebarOpen?: boolean;
}

export function AppShell({
  brand,
  navigation,
  topBarRight,
  children,
  defaultSidebarOpen = true,
}: Readonly<AppShellProps>) {
  const [sidebarOpen, setSidebarOpen] = useState(() => {
    if (typeof globalThis.window === "undefined") return defaultSidebarOpen;
    const stored = globalThis.localStorage.getItem(SIDEBAR_STORAGE_KEY);
    return stored === null ? defaultSidebarOpen : stored === "1";
  });

  useEffect(() => {
    if (typeof globalThis.window === "undefined") return;
    globalThis.localStorage.setItem(SIDEBAR_STORAGE_KEY, sidebarOpen ? "1" : "0");
  }, [sidebarOpen]);

  useEffect(() => {
    function handleKey(event: KeyboardEvent): void {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "b") {
        event.preventDefault();
        setSidebarOpen((s) => !s);
      }
    }
    globalThis.addEventListener("keydown", handleKey);
    return () => globalThis.removeEventListener("keydown", handleKey);
  }, []);

  return (
    <div className="bg-background text-foreground flex min-h-svh flex-col">
      <a
        href="#main-content"
        className="bg-primary text-primary-foreground sr-only z-50 px-3 py-2 focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:rounded-md"
      >
        Skip to main content
      </a>

      <div className="flex flex-1">
        <aside
          data-sidebar-open={sidebarOpen}
          aria-label="Primary"
          className={cn(
            "bg-muted/40 border-border hidden border-r transition-[width] md:flex md:flex-col",
            sidebarOpen ? "md:w-60" : "md:w-14",
          )}
        >
          <div className="border-border flex h-12 items-center gap-2 border-b px-3">
            {sidebarOpen && brand ? <div className="min-w-0 flex-1">{brand}</div> : null}
            <Button
              variant="ghost"
              size="icon-sm"
              aria-label={sidebarOpen ? "Collapse sidebar" : "Expand sidebar"}
              aria-expanded={sidebarOpen}
              onClick={() => setSidebarOpen((s) => !s)}
              className="ml-auto"
            >
              {sidebarOpen ? (
                <PanelLeftClose className="size-4" />
              ) : (
                <PanelLeftOpen className="size-4" />
              )}
            </Button>
          </div>
          <nav className="flex-1 overflow-y-auto p-2">
            <ul className="space-y-0.5">
              {navigation.map((item) => (
                <li key={item.href}>
                  <a
                    href={item.href}
                    aria-current={item.active ? "page" : undefined}
                    className={cn(
                      "hover:bg-accent hover:text-foreground focus-visible:ring-ring text-muted-foreground flex items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none",
                      item.active && "bg-accent text-foreground",
                      !sidebarOpen && "justify-center",
                    )}
                  >
                    {item.icon ? <span className="shrink-0">{item.icon}</span> : null}
                    {sidebarOpen ? <span className="truncate">{item.label}</span> : null}
                  </a>
                </li>
              ))}
            </ul>
          </nav>
        </aside>

        <div className="flex min-w-0 flex-1 flex-col">
          <header className="bg-background border-border flex h-12 shrink-0 items-center gap-2 border-b px-3">
            <Button
              variant="ghost"
              size="icon-sm"
              aria-label="Toggle navigation"
              onClick={() => setSidebarOpen((s) => !s)}
              className="md:hidden"
            >
              <PanelLeftOpen className="size-4" />
            </Button>
            {topBarRight ? <div className="ml-auto">{topBarRight}</div> : null}
          </header>
          <main id="main-content" className="flex-1 overflow-y-auto p-4">
            {children}
          </main>
        </div>
      </div>
    </div>
  );
}
