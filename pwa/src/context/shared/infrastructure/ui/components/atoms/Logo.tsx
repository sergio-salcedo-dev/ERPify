import React from "react";
import Link from "next/link";
import { ShieldCheck } from "lucide-react";

interface LogoProps {
  href?: string;
  className?: string;
  iconClassName?: string;
  textClassName?: string;
}

export const Logo: React.FC<LogoProps & { children?: React.ReactNode }> = ({
  href = "/",
  className = "logo flex items-center gap-2 hover:opacity-80 transition-opacity",
  iconClassName = "logo__icon text-blue-600 w-6 h-6",
  textClassName = "logo__text text-xl font-bold text-slate-900",
  children,
}) => {
  return (
    <Link href={href} className={className}>
      {children || <ShieldCheck className={iconClassName} />}
      <span className={textClassName}>Erpify</span>
    </Link>
  );
};
