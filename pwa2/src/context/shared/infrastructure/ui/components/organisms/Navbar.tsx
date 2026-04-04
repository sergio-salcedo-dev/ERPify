import React, { useState } from "react";
import { motion, AnimatePresence } from "motion/react";
import { Menu, X, ShieldCheck, User as UserIcon, Settings, LogOut, HelpCircle } from "lucide-react";
import { Logo } from "../atoms/Logo";
import { Button } from "../atoms/Button";
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

  const navLinks = [
    { name: "Features", href: "#" },
    { name: "Pricing", href: "#" },
    { name: "About", href: "#" },
  ];

  return (
    <nav className="navbar bg-white border-b border-slate-200 sticky top-0 z-50">
      <div className="navbar__container max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="navbar__inner flex justify-between h-16 items-center">
          <Logo
            iconClassName="navbar__logo-icon text-white w-6 h-6"
            className="navbar__logo flex items-center gap-2 hover:opacity-80 transition-opacity"
            textClassName="navbar__logo-text text-2xl font-bold text-slate-900 tracking-tight"
          >
            <div className="bg-blue-600 p-2 rounded-lg">
              <ShieldCheck className="text-white w-6 h-6" />
            </div>
          </Logo>

          {/* Desktop Menu */}
          <div className="navbar__menu hidden md:flex items-center space-x-8">
            {navLinks.map((link) => (
              <a
                key={link.name}
                href={link.href}
                className="navbar__link text-slate-600 hover:text-blue-600 font-medium transition-colors"
              >
                {link.name}
              </a>
            ))}

            <DropdownMenu>
              <DropdownMenuTrigger
                render={
                  <Button variant="ghost" size="icon" className="navbar__user-trigger rounded-full">
                    <UserIcon className="w-5 h-5 text-slate-600" />
                  </Button>
                }
              />
              <DropdownMenuContent align="end" className="navbar__user-dropdown w-56">
                <DropdownMenuGroup>
                  <DropdownMenuLabel className="navbar__user-label">My Account</DropdownMenuLabel>
                </DropdownMenuGroup>
                <DropdownMenuSeparator />
                <DropdownMenuItem className="navbar__user-item cursor-pointer">
                  <UserIcon className="mr-2 h-4 w-4" />
                  <span>Profile</span>
                </DropdownMenuItem>
                <DropdownMenuItem className="navbar__user-item cursor-pointer">
                  <Settings className="mr-2 h-4 w-4" />
                  <span>Settings</span>
                </DropdownMenuItem>
                <DropdownMenuItem className="navbar__user-item cursor-pointer">
                  <HelpCircle className="mr-2 h-4 w-4" />
                  <span>Support</span>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem className="navbar__user-item cursor-pointer text-rose-600 focus:text-rose-600">
                  <LogOut className="mr-2 h-4 w-4" />
                  <span>Log out</span>
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>

            <Button onClick={onGetStarted} size="md" className="navbar__button rounded-full">
              Get Started
            </Button>
          </div>

          {/* Mobile Menu Button */}
          <div className="navbar__mobile-toggle md:hidden">
            <button onClick={() => setIsMenuOpen(!isMenuOpen)} className="p-2 text-slate-600">
              {isMenuOpen ? <X /> : <Menu />}
            </button>
          </div>
        </div>
      </div>

      {/* Mobile Menu */}
      <AnimatePresence>
        {isMenuOpen && (
          <motion.div
            initial={{ opacity: 0, y: -10 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -10 }}
            className="navbar__mobile-menu md:hidden bg-white border-b border-slate-200 px-4 pt-2 pb-6 space-y-4"
          >
            {navLinks.map((link) => (
              <a
                key={link.name}
                href={link.href}
                className="navbar__link block text-slate-600 font-medium"
              >
                {link.name}
              </a>
            ))}
            <Button onClick={onGetStarted} size="lg" className="navbar__button w-full rounded-xl">
              Get Started
            </Button>
          </motion.div>
        )}
      </AnimatePresence>
    </nav>
  );
};
