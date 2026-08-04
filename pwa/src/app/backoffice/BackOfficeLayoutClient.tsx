"use client";

import { useEffect, useState } from "react";
import { useRouter, usePathname } from "next/navigation";
import { Menu, Bell, PanelLeftClose, PanelLeftOpen, Search } from "lucide-react";
import { Logo, MonogramAvatar, SidebarItem, ThemeToggle } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from "@/components/ui/sheet";
import { TooltipProvider } from "@/components/ui/tooltip";
import { sectionTitleFor } from "./_lib/sectionTitle";
import { backofficeMenuGroups, accountMenuItem, type NavItem } from "./_lib/backofficeMenu";
import { RequireAuth, DevSessionSwitcher } from "@/context/shared/access/infrastructure/ui";
import { useSession } from "@/context/shared/access/application/useSession";
import { isDevToolsAvailable } from "@/context/shared/dev-tools/domain/isDevToolsAvailable";
import { Routes } from "@/context/shared/routing/domain/Routes";

const SIDEBAR_STORAGE_KEY = "erpify:sidebar-open";

export default function BackOfficeLayoutClient({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);
  const [isCompact, setIsCompact] = useState(() => {
    if (globalThis.window === undefined) return false;
    const stored = globalThis.localStorage.getItem(SIDEBAR_STORAGE_KEY);
    return stored === null ? false : stored !== "1";
  });
  const router = useRouter();
  const pathname = usePathname();
  const sectionTitle = sectionTitleFor(pathname);
  const { logout, session } = useSession();

  useEffect(() => {
    if (globalThis.window === undefined) return;
    globalThis.localStorage.setItem(SIDEBAR_STORAGE_KEY, isCompact ? "0" : "1");
  }, [isCompact]);

  useEffect(() => {
    function handleKey(event: KeyboardEvent): void {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "b") {
        event.preventDefault();
        setIsCompact((compact) => !compact);
      }
    }
    globalThis.addEventListener("keydown", handleKey);
    return () => globalThis.removeEventListener("keydown", handleKey);
  }, []);

  const menuGroups = backofficeMenuGroups;

  const handleNavigation = (path: string) => {
    if (path === Routes.HOME) {
      // The account menu's logout entry targets HOME (the public landing). logout()
      // revokes the server session (dropping its cookie) then clears client state;
      // wait for it to settle so the cookie is gone before leaving, then use a
      // full-document navigation rather than router.push: an SPA push keeps this
      // guarded subtree mounted, so RequireAuth observes the just-cleared session
      // mid-transition and redirects to /login before the push to HOME commits. A
      // hard navigation discards all in-memory client state and lands on the public
      // landing unconditionally — and it fires even if the server revoke failed.
      void logout().finally(() => {
        globalThis.location.assign(Routes.HOME);
      });
      return;
    }
    router.push(path);
    setIsSidebarOpen(false);
  };

  const navigateTo = (path: string) => () => handleNavigation(path);

  // The top-bar menu mirrors the sidebar's Account group rather than declaring its own
  // entries, so the two can never drift. Logout is split out: it is the only entry whose
  // target leaves the back office, and it reads as destructive.
  const accountEntries = accountMenuItem.subItems ?? [];
  const accountLinks = accountEntries.filter((entry) => entry.path !== Routes.HOME);
  const accountLogout = accountEntries.find((entry) => entry.path === Routes.HOME);
  const accountEmail = session?.user.email ?? "";

  const isItemActive = (item: NavItem) => {
    if (pathname === item.path) return true;
    if (item.subItems) {
      return item.subItems.some((sub) => pathname === sub.path);
    }
    return false;
  };

  return (
    <RequireAuth>
      <TooltipProvider>
        <div className="bo-layout min-h-screen bg-background flex font-sans">
          <a
            href="#main-content"
            className="bo-layout__skip-link bg-primary text-primary-foreground sr-only z-50 px-3 py-2 focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:rounded-md"
          >
            Skip to main content
          </a>

          {/* Sidebar Desktop */}
          <aside
            data-sidebar-open={!isCompact}
            className={`bo-layout__sidebar hidden md:flex flex-col bg-card border-r border-border sticky top-0 h-screen shadow-sm transition-all duration-300 ${
              isCompact ? "w-20" : "w-64"
            }`}
          >
            <div className="bo-layout__sidebar-header flex items-center border-b border-border h-16 px-4">
              {!isCompact && (
                <Logo
                  href="/backoffice"
                  variant="badge"
                  size="md"
                  className="bo-layout__logo"
                  iconClassName="bo-layout__logo-icon"
                  textClassName="bo-layout__logo-text"
                />
              )}
              {isCompact && (
                <Logo
                  href="/backoffice"
                  variant="badge"
                  size="md"
                  iconOnly
                  className="bo-layout__logo bo-layout__logo--compact mx-auto"
                  iconClassName="bo-layout__logo-icon"
                />
              )}
            </div>

            <nav className="bo-layout__sidebar-nav flex-grow p-3 space-y-6 overflow-y-auto">
              {menuGroups.map((group) => (
                <div key={group.label} className="bo-layout__nav-group space-y-1">
                  {!isCompact && (
                    <p className="bo-layout__nav-label text-[10px] font-semibold text-muted-foreground uppercase tracking-widest px-3 mb-2">
                      {group.label}
                    </p>
                  )}
                  {group.items.map((item) => (
                    <SidebarItem
                      key={item.name}
                      {...item}
                      isActive={isItemActive(item)}
                      onClick={handleNavigation}
                      isCompact={isCompact}
                    />
                  ))}
                </div>
              ))}
            </nav>

            {/* User Profile at bottom */}
            <div className="bo-layout__footer p-3 border-t border-border">
              {!isCompact && (
                <p className="bo-layout__nav-label text-[10px] font-semibold text-muted-foreground uppercase tracking-widest px-3 mb-2">
                  Account
                </p>
              )}
              <SidebarItem
                {...accountMenuItem}
                isActive={isItemActive(accountMenuItem)}
                onClick={handleNavigation}
                isCompact={isCompact}
              />
            </div>
          </aside>

          {/* Mobile Header */}
          <div className="bo-layout__header-mobile md:hidden fixed top-0 left-0 right-0 bg-card border-b border-border h-14 flex items-center justify-between px-4 z-50">
            <Logo
              href="/backoffice"
              variant="badge"
              size="md"
              className="bo-layout__logo-mobile"
              iconClassName="bo-layout__logo-icon"
              textClassName="bo-layout__logo-text"
            />
            <div className="bo-layout__header-mobile-actions flex items-center gap-1">
              <ThemeToggle testId="bo-layout__header-mobile-theme" />
              <Sheet open={isSidebarOpen} onOpenChange={setIsSidebarOpen}>
                <SheetTrigger
                  render={
                    <button
                      type="button"
                      aria-label="Open navigation menu"
                      className="bo-layout__toggle-mobile p-2 text-foreground"
                    >
                      <Menu className="w-6 h-6" aria-hidden />
                    </button>
                  }
                />
                <SheetContent side="left" className="bo-layout__sidebar-mobile p-0 w-72">
                  <SheetHeader className="bo-layout__sidebar-mobile-header p-4 flex flex-row items-center justify-between border-b border-border">
                    <SheetTitle className="hidden">Navigation Menu</SheetTitle>
                    <Logo
                      href="/backoffice"
                      variant="badge"
                      size="md"
                      className="bo-layout__logo-mobile"
                      iconClassName="bo-layout__logo-icon"
                      textClassName="bo-layout__logo-text"
                    />
                  </SheetHeader>
                  <nav className="bo-layout__sidebar-mobile-nav p-4 space-y-6 overflow-y-auto h-[calc(100vh-64px)]">
                    {menuGroups.map((group) => (
                      <div key={group.label} className="bo-layout__mobile-group space-y-1">
                        <p className="text-[10px] font-semibold text-muted-foreground uppercase tracking-widest px-4 mb-2">
                          {group.label}
                        </p>
                        {group.items.map((item) => (
                          <div key={item.name} className="bo-layout__sidebar-mobile-item-wrapper">
                            <button
                              type="button"
                              onClick={() => handleNavigation(item.path)}
                              title={item.name}
                              data-testid={item.testId ? `${item.testId}--mobile` : undefined}
                              className={`bo-layout__sidebar-mobile-link w-full flex items-center gap-3 p-3 rounded-md font-semibold transition-all ${
                                pathname === item.path
                                  ? "bg-primary/15 text-primary"
                                  : "text-muted-foreground hover:bg-accent"
                              }`}
                            >
                              <item.icon className="w-5 h-5" />
                              <span className="text-sm">{item.name}</span>
                            </button>
                            {item.subItems && (
                              <div className="ml-8 mt-1 space-y-1">
                                {item.subItems.map((subItem) => (
                                  <button
                                    type="button"
                                    key={subItem.name}
                                    onClick={navigateTo(subItem.path)}
                                    title={subItem.name}
                                    data-testid={
                                      subItem.testId ? `${subItem.testId}--mobile` : undefined
                                    }
                                    className={`w-full flex items-center gap-2.5 p-2 rounded-md text-xs font-semibold transition-all ${
                                      pathname === subItem.path
                                        ? "text-primary bg-primary/10"
                                        : "text-muted-foreground hover:bg-accent"
                                    }`}
                                  >
                                    {subItem.icon && <subItem.icon className="w-3.5 h-3.5" />}
                                    {subItem.name}
                                  </button>
                                ))}
                              </div>
                            )}
                          </div>
                        ))}
                      </div>
                    ))}

                    <div className="bo-layout__mobile-group space-y-1 pt-4 border-t border-border">
                      <p className="text-[10px] font-semibold text-muted-foreground uppercase tracking-widest px-4 mb-2">
                        Account
                      </p>
                      <div className="bo-layout__sidebar-mobile-item-wrapper">
                        <button
                          type="button"
                          onClick={() => handleNavigation(accountMenuItem.path)}
                          title={accountMenuItem.name}
                          className={`bo-layout__sidebar-mobile-link w-full flex items-center gap-3 p-3 rounded-md font-semibold transition-all ${
                            pathname === accountMenuItem.path
                              ? "bg-primary/15 text-primary"
                              : "text-muted-foreground hover:bg-accent"
                          }`}
                        >
                          <accountMenuItem.icon className="w-5 h-5" />
                          <span className="text-sm">{accountMenuItem.name}</span>
                        </button>
                        <div className="ml-8 mt-1 space-y-1">
                          {accountMenuItem.subItems?.map((subItem) => (
                            <button
                              type="button"
                              key={subItem.name}
                              onClick={() => handleNavigation(subItem.path)}
                              title={subItem.name}
                              data-testid={subItem.testId ? `${subItem.testId}--mobile` : undefined}
                              className={`w-full flex items-center gap-2.5 p-2 rounded-md text-xs font-semibold transition-all ${
                                pathname === subItem.path
                                  ? "text-primary bg-primary/10"
                                  : "text-muted-foreground hover:bg-accent"
                              }`}
                            >
                              {subItem.icon && <subItem.icon className="w-3.5 h-3.5" />}
                              {subItem.name}
                            </button>
                          ))}
                        </div>
                      </div>
                    </div>
                  </nav>
                </SheetContent>
              </Sheet>
            </div>
          </div>

          {/* Main column: desktop top bar + scrollable content */}
          <div className="bo-layout__column flex min-w-0 flex-1 flex-col">
            <header className="bo-layout__topbar hidden md:flex items-center gap-3 bg-card border-b border-border h-16 px-4">
              <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                aria-label={isCompact ? "Expand sidebar" : "Collapse sidebar"}
                aria-expanded={!isCompact}
                title={isCompact ? "Expand sidebar (Ctrl/Cmd+B)" : "Collapse sidebar (Ctrl/Cmd+B)"}
                data-testid="bo-layout__topbar-toggle"
                onClick={() => setIsCompact((compact) => !compact)}
                className="bo-layout__topbar-toggle"
              >
                {isCompact ? (
                  <PanelLeftOpen className="size-4" aria-hidden />
                ) : (
                  <PanelLeftClose className="size-4" aria-hidden />
                )}
                <span className="sr-only">{isCompact ? "Expand sidebar" : "Collapse sidebar"}</span>
              </Button>

              <span
                data-testid="bo-layout__topbar-title"
                className="bo-layout__topbar-title text-foreground text-sm font-semibold truncate"
              >
                {sectionTitle}
              </span>

              <div className="bo-layout__topbar-actions ml-auto flex items-center gap-1">
                {isDevToolsAvailable() ? <DevSessionSwitcher /> : null}
                <ThemeToggle testId="bo-layout__topbar-theme" />
                <Button
                  type="button"
                  variant="ghost"
                  size="icon-sm"
                  aria-label="Search"
                  title="Search"
                  data-testid="bo-layout__topbar-search"
                  className="bo-layout__topbar-search"
                >
                  <Search className="size-4" aria-hidden />
                  <span className="sr-only">Search</span>
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon-sm"
                  aria-label="Notifications"
                  title="Notifications"
                  data-testid="bo-layout__topbar-notifications"
                  className="bo-layout__topbar-notifications relative"
                >
                  <Bell className="size-4" aria-hidden />
                  <span
                    className="bo-layout__topbar-badge absolute right-1.5 top-1.5 size-1.5 rounded-full bg-primary"
                    aria-hidden
                  />
                  <span className="sr-only">Notifications</span>
                </Button>
                <DropdownMenu>
                  <DropdownMenuTrigger
                    render={
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Account menu"
                        title="Account menu"
                        data-testid="bo-layout__topbar-account"
                        className="bo-layout__topbar-account"
                      >
                        {/* The monogram is aria-hidden by contract, so the trigger keeps its
                          own static name in aria-label and the sr-only fallback. It is fed the
                          local part because `initials()` reads a single-word input as a name and
                          takes its first two characters — on a whole address that yields "A@". */}
                        <MonogramAvatar
                          name={accountEmail.split("@")[0]}
                          className="size-6 rounded-md text-[10px]"
                        />
                        <span className="sr-only">Account menu</span>
                      </Button>
                    }
                  />
                  <DropdownMenuContent
                    align="end"
                    className="w-56"
                    data-testid="bo-layout__account-menu"
                  >
                    <DropdownMenuGroup>
                      <DropdownMenuLabel className="truncate" title={accountEmail}>
                        {accountEmail}
                      </DropdownMenuLabel>
                      {accountLinks.map((entry) => (
                        <DropdownMenuItem
                          key={entry.name}
                          onClick={navigateTo(entry.path)}
                          title={entry.name}
                          data-testid={entry.testId ? `${entry.testId}--menu` : undefined}
                        >
                          {entry.icon ? <entry.icon className="size-4" aria-hidden /> : null}
                          {entry.name}
                        </DropdownMenuItem>
                      ))}
                    </DropdownMenuGroup>
                    {accountLogout ? (
                      <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                          variant="destructive"
                          onClick={navigateTo(accountLogout.path)}
                          title={accountLogout.name}
                          data-testid={
                            accountLogout.testId ? `${accountLogout.testId}--menu` : undefined
                          }
                        >
                          {accountLogout.icon ? (
                            <accountLogout.icon className="size-4" aria-hidden />
                          ) : null}
                          {accountLogout.name}
                        </DropdownMenuItem>
                      </>
                    ) : null}
                  </DropdownMenuContent>
                </DropdownMenu>
              </div>
            </header>

            {/* No overflow on <main>: the window is the scroll container (the
              column grows with content), and an overflow value here would
              re-scope every descendant `position: sticky` to a scrollport
              that never scrolls — breaking e.g. the banks bulk bar. Wide
              content (tables) brings its own overflow-x wrapper. */}
            <main id="main-content" className="bo-layout__main flex-grow pt-14 md:pt-0">
              <div className="bo-layout__content mx-auto p-4 md:p-8">{children}</div>
            </main>
          </div>
        </div>
      </TooltipProvider>
    </RequireAuth>
  );
}
