import React from "react";
import { ShieldCheck } from "lucide-react";

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
          <div className="footer__logo flex items-center gap-2">
            <ShieldCheck className="footer__logo-icon text-blue-600 w-6 h-6" />
            <span className="footer__logo-text text-xl font-bold text-slate-900">Erpify</span>
          </div>
          <div className="footer__links flex space-x-6 text-slate-500 font-medium">
            {footerLinks.map((link) => (
              <a key={link.name} href={link.href} className="footer__link hover:text-blue-600">
                {link.name}
              </a>
            ))}
          </div>
          <p className="footer__copyright text-slate-400 text-sm">
            © 2026 Erpify SaaS. All rights reserved.
          </p>
        </div>
      </div>
    </footer>
  );
};
