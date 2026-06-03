import React, { useState } from "react";
import { Menu, X, Wrench } from "lucide-react";
import Link from "next/link";
import { Logo } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { isDevToolsAvailable } from "@/context/shared/dev-tools/domain/isDevToolsAvailable";
import { Routes } from "@/context/shared/domain/types/routes";

interface NavbarProps {
  goToBackoffice: () => void;
}

export const Navbar: React.FC<NavbarProps> = ({ goToBackoffice }) => {
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const showDevTools = isDevToolsAvailable();

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

            <Button
              onClick={goToBackoffice}
              size="default"
              className="navbar__button rounded-full"
              data-testid="navbar__go-to-backoffice-button"
            >
              Backoffice
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
            onClick={goToBackoffice}
            size="lg"
            className="navbar__button w-full rounded-xl"
            data-testid="navbar__go-to-backoffice-button--mobile"
          >
            Backoffice
          </Button>
        </div>
      )}
    </nav>
  );
};
