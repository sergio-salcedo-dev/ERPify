import React, { useState } from "react";
import { Menu, X, User as UserIcon, Settings, LogOut, HelpCircle, Wrench } from "lucide-react";
import Link from "next/link";
import { Logo } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { isDevToolsAvailable } from "@/context/shared/dev-tools/domain/isDevToolsAvailable";
import { Routes } from "@/context/shared/domain/types/routes";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

interface NavbarProps {
  onGetStarted: () => void;
}

export const Navbar: React.FC<NavbarProps> = ({ onGetStarted }) => {
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const showDevTools = isDevToolsAvailable();

  const navLinks = [
    { name: "Features", href: "#", testId: "navbar__link-features" },
    { name: "Pricing", href: "#", testId: "navbar__link-pricing" },
    { name: "About", href: "#", testId: "navbar__link-about" },
  ];

  return (
    <nav className="navbar bg-white border-b border-slate-200 sticky top-0 z-50">
      <div className="navbar__container max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="navbar__inner flex justify-between h-16 items-center">
          <Logo
            href="/"
            variant="badge"
            size="lg"
            className="navbar__logo"
            textClassName="navbar__logo-text"
            iconClassName="navbar__logo-icon"
          />

          {/* Desktop Menu */}
          <div className="navbar__menu hidden md:flex items-center space-x-8">
            {navLinks.map((link) => (
              <a
                key={link.name}
                href={link.href}
                data-testid={link.testId}
                className="navbar__link text-slate-600 hover:text-blue-600 font-medium transition-colors"
              >
                {link.name}
              </a>
            ))}

            <Link
              href={Routes.STATUS}
              className="navbar__link text-slate-600 hover:text-blue-600 font-medium transition-colors"
              data-testid="navbar__link-status"
            >
              Status
            </Link>

            {showDevTools ? (
              <Link
                href={Routes.DEV_TOOLS}
                className="navbar__link navbar__link--dev-tools text-amber-700 hover:text-amber-900 font-medium transition-colors inline-flex items-center gap-1.5"
                title="Internal QA / engineering tools (dev/test only)"
                data-testid="navbar__dev-tools-link"
              >
                <Wrench className="w-4 h-4" aria-hidden="true" />
                Dev Tools
              </Link>
            ) : null}

            <DropdownMenu>
              <DropdownMenuTrigger
                render={
                  <Button
                    variant="ghost"
                    size="icon"
                    className="navbar__user-trigger rounded-full"
                    data-testid="navbar__user-trigger"
                  >
                    <UserIcon className="w-5 h-5 text-slate-600" />
                  </Button>
                }
              />
              <DropdownMenuContent align="end" className="navbar__user-dropdown w-56">
                <DropdownMenuGroup>
                  <DropdownMenuLabel className="navbar__user-label">My Account</DropdownMenuLabel>
                </DropdownMenuGroup>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  className="navbar__user-item cursor-pointer"
                  data-testid="navbar__user-item-profile"
                >
                  <UserIcon className="mr-2 h-4 w-4" />
                  <span>Profile</span>
                </DropdownMenuItem>
                <DropdownMenuItem
                  className="navbar__user-item cursor-pointer"
                  data-testid="navbar__user-item-settings"
                >
                  <Settings className="mr-2 h-4 w-4" />
                  <span>Settings</span>
                </DropdownMenuItem>
                <DropdownMenuItem
                  className="navbar__user-item cursor-pointer"
                  data-testid="navbar__user-item-support"
                >
                  <HelpCircle className="mr-2 h-4 w-4" />
                  <span>Support</span>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  className="navbar__user-item cursor-pointer text-rose-600 focus:text-rose-600"
                  data-testid="navbar__user-item-logout"
                >
                  <LogOut className="mr-2 h-4 w-4" />
                  <span>Log out</span>
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>

            <Button
              onClick={onGetStarted}
              size="default"
              className="navbar__button rounded-full"
              data-testid="navbar__get-started"
            >
              Get Started
            </Button>
          </div>

          {/* Mobile Menu Button */}
          <div className="navbar__mobile-toggle md:hidden">
            <button
              onClick={() => setIsMenuOpen(!isMenuOpen)}
              className="p-2 text-slate-600"
              aria-label={isMenuOpen ? "Close navigation menu" : "Open navigation menu"}
              data-testid="navbar__mobile-menu-toggle"
            >
              {isMenuOpen ? <X /> : <Menu />}
            </button>
          </div>
        </div>
      </div>

      {/* Mobile Menu */}
      {isMenuOpen && (
        <div className="navbar__mobile-menu md:hidden bg-white border-b border-slate-200 px-4 pt-2 pb-6 space-y-4 animate-in fade-in-0 slide-in-from-top-2 duration-200">
          {navLinks.map((link) => (
            <a
              key={link.name}
              href={link.href}
              data-testid={`${link.testId}--mobile`}
              className="navbar__link block text-slate-600 font-medium"
            >
              {link.name}
            </a>
          ))}
          <Link
            href={Routes.STATUS}
            className="navbar__link block text-slate-600 font-medium"
            data-testid="navbar__link-status--mobile"
          >
            Status
          </Link>
          {showDevTools ? (
            <Link
              href={Routes.DEV_TOOLS}
              className="navbar__link navbar__link--dev-tools text-amber-700 hover:text-amber-900 font-medium inline-flex items-center gap-1.5"
              title="Internal QA / engineering tools (dev/test only)"
              data-testid="navbar__mobile-dev-tools-link"
            >
              <Wrench className="w-4 h-4" aria-hidden="true" />
              Dev Tools
            </Link>
          ) : null}
          <Button
            onClick={onGetStarted}
            size="lg"
            className="navbar__button w-full rounded-xl"
            data-testid="navbar__get-started--mobile"
          >
            Get Started
          </Button>
        </div>
      )}
    </nav>
  );
};
