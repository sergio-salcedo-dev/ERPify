"use client";

import { useState } from "react";
import { useRouter, usePathname } from "next/navigation";
import {
  LucideIcon,
  LayoutDashboard,
  User,
  LogOut,
  Menu,
  ShieldCheck,
  Settings as SettingsIcon,
  Bell,
  Activity,
  ChevronLeft,
  ChevronRight,
} from "lucide-react";
import { Logo } from "@/context/shared/infrastructure/ui/components/atoms/Logo";
import { SidebarItem } from "@/context/shared/infrastructure/ui/components/molecules/SidebarItem";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from "@/components/ui/sheet";

interface NavSubItem {
  name: string;
  path: string;
  icon?: LucideIcon;
}

interface NavItem {
  name: string;
  icon: LucideIcon;
  path: string;
  subItems?: NavSubItem[];
}

interface NavGroup {
  label: string;
  items: NavItem[];
}

export default function BackOfficeLayout({ children }: { children: React.ReactNode }) {
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);
  const [isCompact, setIsCompact] = useState(false);
  const router = useRouter();
  const pathname = usePathname();

  const menuGroups: NavGroup[] = [
    {
      label: "General",
      items: [{ name: "Dashboard", icon: LayoutDashboard, path: "/backoffice" }],
    },
    {
      label: "System",
      items: [
        {
          name: "Administration",
          icon: SettingsIcon,
          path: "/backoffice/administration",
          subItems: [{ name: "Health", path: "/backoffice/health", icon: Activity }],
        },
      ],
    },
  ];

  const userProfileItem: NavItem = {
    name: "User Profile",
    icon: User,
    path: "/backoffice/profile",
    subItems: [
      { name: "Notifications", path: "/backoffice/profile/notifications", icon: Bell },
      { name: "Settings", path: "/backoffice/profile/settings", icon: SettingsIcon },
      { name: "Logout", path: "/", icon: LogOut },
    ],
  };

  const handleNavigation = (path: string) => {
    if (path === "/") {
      // Simulate logout
      setTimeout(() => router.push("/"), 500);
    } else {
      router.push(path);
    }
    setIsSidebarOpen(false);
  };

  const isItemActive = (item: NavItem) => {
    if (pathname === item.path) return true;
    if (item.subItems) {
      return item.subItems.some((sub) => pathname === sub.path);
    }
    return false;
  };

  return (
    <div className="bo-layout min-h-screen bg-slate-50 flex font-sans">
      {/* Sidebar Desktop */}
      <aside
        className={`bo-layout__sidebar hidden md:flex flex-col bg-white border-r border-slate-200 sticky top-0 h-screen shadow-sm transition-all duration-300 ${
          isCompact ? "w-20" : "w-64"
        }`}
      >
        <div className="bo-layout__sidebar-header flex items-center justify-between border-b border-slate-100 h-16 px-4">
          {!isCompact && (
            <Logo
              href="/backoffice"
              className="bo-layout__logo flex items-center gap-2 hover:opacity-80 transition-opacity"
              iconClassName="bo-layout__logo-icon text-white w-5 h-5"
              textClassName="bo-layout__logo-text text-lg font-bold text-slate-900 tracking-tight"
            >
              <div className="bg-blue-600 p-1.5 rounded-lg">
                <ShieldCheck className="text-white w-4 h-4" />
              </div>
            </Logo>
          )}
          {isCompact && (
            <div className="bg-blue-600 p-2 rounded-lg mx-auto">
              <ShieldCheck className="text-white w-5 h-5" />
            </div>
          )}
          <button
            onClick={() => setIsCompact(!isCompact)}
            className={`bo-layout__compact-toggle p-1.5 rounded-lg hover:bg-slate-100 text-slate-400 transition-colors ${
              isCompact ? "hidden" : ""
            }`}
          >
            <ChevronLeft className="w-4 h-4" />
          </button>
        </div>

        {isCompact && (
          <button
            onClick={() => setIsCompact(false)}
            className="bo-layout__expand-toggle mx-auto mt-4 p-2 rounded-lg hover:bg-slate-100 text-slate-400"
          >
            <ChevronRight className="w-5 h-5" />
          </button>
        )}

        <nav className="bo-layout__sidebar-nav flex-grow p-3 space-y-6 overflow-y-auto">
          {menuGroups.map((group) => (
            <div key={group.label} className="bo-layout__nav-group space-y-1">
              {!isCompact && (
                <p className="bo-layout__nav-label text-[10px] font-bold text-slate-400 uppercase tracking-widest px-3 mb-2">
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
        <div className="bo-layout__footer p-3 border-t border-slate-100">
          {!isCompact && (
            <p className="bo-layout__nav-label text-[10px] font-bold text-slate-400 uppercase tracking-widest px-3 mb-2">
              Account
            </p>
          )}
          <SidebarItem
            {...userProfileItem}
            isActive={isItemActive(userProfileItem)}
            onClick={handleNavigation}
            isCompact={isCompact}
          />
        </div>
      </aside>

      {/* Mobile Header */}
      <div className="bo-layout__header-mobile md:hidden fixed top-0 left-0 right-0 bg-white border-b border-slate-200 h-14 flex items-center justify-between px-4 z-50">
        <Logo
          href="/backoffice"
          className="bo-layout__logo-mobile flex items-center gap-2 hover:opacity-80 transition-opacity"
          iconClassName="text-blue-600 w-5 h-5"
          textClassName="text-lg font-bold text-slate-900"
        />
        <Sheet open={isSidebarOpen} onOpenChange={setIsSidebarOpen}>
          <SheetTrigger
            render={
              <button className="bo-layout__toggle-mobile p-2 text-slate-600">
                <Menu className="w-6 h-6" />
              </button>
            }
          />
          <SheetContent side="left" className="bo-layout__sidebar-mobile p-0 w-72">
            <SheetHeader className="bo-layout__sidebar-mobile-header p-4 flex flex-row items-center justify-between border-b border-slate-100">
              <SheetTitle className="hidden">Navigation Menu</SheetTitle>
              <Logo
                href="/backoffice"
                className="bo-layout__logo-mobile flex items-center gap-2 hover:opacity-80 transition-opacity"
                iconClassName="text-blue-600 w-5 h-5"
                textClassName="text-lg font-bold text-slate-900"
              />
            </SheetHeader>
            <nav className="bo-layout__sidebar-mobile-nav p-4 space-y-6 overflow-y-auto h-[calc(100vh-64px)]">
              {menuGroups.map((group) => (
                <div key={group.label} className="bo-layout__mobile-group space-y-1">
                  <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest px-4 mb-2">
                    {group.label}
                  </p>
                  {group.items.map((item) => (
                    <div key={item.name} className="bo-layout__sidebar-mobile-item-wrapper">
                      <button
                        onClick={() => handleNavigation(item.path)}
                        title={item.name}
                        className={`bo-layout__sidebar-mobile-link w-full flex items-center gap-3 p-3 rounded-xl font-bold transition-all ${
                          pathname === item.path
                            ? "bg-blue-50 text-blue-600"
                            : "text-slate-500 hover:bg-slate-50"
                        }`}
                      >
                        <item.icon className="w-5 h-5" />
                        <span className="text-sm">{item.name}</span>
                      </button>
                      {item.subItems && (
                        <div className="ml-8 mt-1 space-y-1">
                          {item.subItems.map((subItem) => (
                            <button
                              key={subItem.name}
                              onClick={() => handleNavigation(subItem.path)}
                              title={subItem.name}
                              className={`w-full flex items-center gap-2.5 p-2 rounded-lg text-xs font-bold transition-all ${
                                pathname === subItem.path
                                  ? "text-blue-600 bg-blue-50/50"
                                  : "text-slate-500 hover:bg-slate-50"
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

              <div className="bo-layout__mobile-group space-y-1 pt-4 border-t border-slate-100">
                <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest px-4 mb-2">
                  Account
                </p>
                <div className="bo-layout__sidebar-mobile-item-wrapper">
                  <button
                    onClick={() => handleNavigation(userProfileItem.path)}
                    title={userProfileItem.name}
                    className={`bo-layout__sidebar-mobile-link w-full flex items-center gap-3 p-3 rounded-xl font-bold transition-all ${
                      pathname === userProfileItem.path
                        ? "bg-blue-50 text-blue-600"
                        : "text-slate-500 hover:bg-slate-50"
                    }`}
                  >
                    <userProfileItem.icon className="w-5 h-5" />
                    <span className="text-sm">{userProfileItem.name}</span>
                  </button>
                  <div className="ml-8 mt-1 space-y-1">
                    {userProfileItem.subItems?.map((subItem) => (
                      <button
                        key={subItem.name}
                        onClick={() => handleNavigation(subItem.path)}
                        title={subItem.name}
                        className={`w-full flex items-center gap-2.5 p-2 rounded-lg text-xs font-bold transition-all ${
                          pathname === subItem.path
                            ? "text-blue-600 bg-blue-50/50"
                            : "text-slate-500 hover:bg-slate-50"
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

      {/* Main Content */}
      <main className="bo-layout__main flex-grow md:pt-0 pt-14 overflow-auto">
        <div className="bo-layout__content max-w-6xl mx-auto p-4 md:p-8">{children}</div>
      </main>
    </div>
  );
}
