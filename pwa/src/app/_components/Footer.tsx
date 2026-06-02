import React from "react";
import Link from "next/link";
import { Logo } from "@/components/erpify";
import { Routes } from "@/context/shared/domain/types/routes";

export const Footer: React.FC = () => {
  const footerLinks = [
    { name: "Privacy Policy", href: "#" },
    { name: "Terms of Service", href: "#" },
    { name: "Contact", href: "#" },
  ];

  return (
    <footer className="footer bg-white border-t border-slate-200 py-12">
      <div className="footer__container max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="footer__inner flex flex-col md:flex-row justify-between items-center gap-8">
          <Logo
            href="/"
            variant="badge"
            size="lg"
            className="footer__logo"
            iconClassName="footer__logo-icon"
            textClassName="footer__logo-text"
          />
          <div className="footer__links flex space-x-6 text-slate-500 font-medium">
            {footerLinks.map((link) => (
              <a key={link.name} href={link.href} className="footer__link hover:text-primary">
                {link.name}
              </a>
            ))}
            <Link
              href={Routes.STATUS}
              className="footer__link hover:text-primary"
              data-testid="footer__link-status"
            >
              Status
            </Link>
          </div>
          <p className="footer__copyright text-slate-400 text-sm">
            © 2026 Erpify SaaS. All rights reserved.
          </p>
        </div>
      </div>
    </footer>
  );
};
