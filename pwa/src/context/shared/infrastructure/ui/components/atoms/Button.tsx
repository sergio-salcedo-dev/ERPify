import React from "react";
import { motion, HTMLMotionProps } from "motion/react";
import { cn } from "@/lib/utils";

interface ButtonProps extends Omit<
  HTMLMotionProps<"button">,
  "onAnimationStart" | "onDrag" | "onDragEnd" | "onDragStart" | "style"
> {
  variant?: "primary" | "secondary" | "outline" | "emerald" | "slate" | "ghost" | "link";
  size?: "sm" | "md" | "lg" | "xl" | "icon";
  loading?: boolean;
}

export const Button: React.FC<ButtonProps> = ({
  variant = "primary",
  size = "md",
  loading = false,
  children,
  className = "",
  disabled,
  ...props
}) => {
  const baseStyles =
    "btn font-bold transition-all disabled:opacity-50 flex items-center justify-center gap-2 rounded-2xl";

  const variants = {
    primary: "btn--primary bg-blue-600 text-white hover:bg-blue-700 shadow-sm",
    secondary:
      "btn--secondary bg-white text-slate-700 border border-slate-200 hover:bg-slate-50 shadow-sm",
    outline:
      "btn--outline bg-transparent text-slate-600 border border-slate-200 hover:border-blue-600 hover:text-blue-600",
    emerald: "btn--emerald bg-emerald-600 text-white hover:bg-emerald-700 shadow-sm",
    slate: "btn--slate bg-slate-900 text-white hover:bg-slate-800 shadow-sm",
    ghost: "btn--ghost hover:bg-slate-100",
    link: "btn--link text-blue-600 underline-offset-4 hover:underline",
  };

  const sizes = {
    sm: "btn--sm px-4 py-2 text-sm",
    md: "btn--md px-5 py-2.5 text-base",
    lg: "btn--lg px-6 py-3 text-lg",
    xl: "btn--xl px-8 py-4 text-xl",
    icon: "btn--icon p-2",
  };

  // Map our custom variants to shadcn base if needed, or just use our custom ones
  // For now, we'll stick to our custom styles but use ShadcnButton as the base primitive if we wanted
  // However, ShadcnButton is already a styled component.
  // Let's use motion.button directly but with Shadcn's utility classes if preferred.
  // Actually, the user wants us to use Shadcn/UI.

  return (
    <motion.button
      whileHover={{ scale: 1.02 }}
      whileTap={{ scale: 0.98 }}
      className={cn(
        baseStyles,
        variants[variant as keyof typeof variants],
        sizes[size as keyof typeof sizes],
        className,
      )}
      disabled={disabled || loading}
      {...props}
    >
      {loading ? "Loading..." : children}
    </motion.button>
  );
};
