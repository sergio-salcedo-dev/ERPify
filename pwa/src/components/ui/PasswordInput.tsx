"use client";

import { useState, type ComponentProps } from "react";
import { Eye, EyeOff } from "lucide-react";
import { Input } from "@/components/ui/input";
import { cn } from "@/components/cn";

/**
 * Static accessible name — identical in both states, so the control's name never
 * changes under assistive tech. The pressed state is carried by `aria-pressed`,
 * not by rewording the label.
 */
const TOGGLE_LABEL = "Mostrar/Ocultar contraseña";

interface PasswordInputProps extends Omit<ComponentProps<"input">, "type"> {
  /** Test id for the reveal toggle button (the input keeps the spread `data-testid`). */
  toggleTestId?: string;
  /** Initial reveal state. The invitation-accept UX defaults to REVEALED. */
  defaultRevealed?: boolean;
}

/**
 * Password field with an inline reveal toggle. A single control renders the
 * value as plain text or masked; the toggle is a >= 44px touch target with a
 * static name and `aria-pressed` state, and swaps the `Eye` / `EyeOff` icon so
 * the affordance reads without color alone. The reveal state is local UI state —
 * the typed value flows through the spread input props unchanged, so RHF keeps
 * owning it (id, aria, ref, name, change/blur all forward to the real input).
 */
export function PasswordInput({
  className,
  toggleTestId,
  defaultRevealed = true,
  ref,
  ...inputProps
}: Readonly<PasswordInputProps>) {
  const [revealed, setRevealed] = useState(defaultRevealed);
  const ToggleIcon = revealed ? EyeOff : Eye;

  return (
    <div className={cn("password-input relative", className)}>
      <Input {...inputProps} ref={ref} type={revealed ? "text" : "password"} className="pr-11" />
      <button
        type="button"
        onClick={() => setRevealed((current) => !current)}
        aria-pressed={revealed}
        aria-label={TOGGLE_LABEL}
        title={TOGGLE_LABEL}
        className="password-input__toggle text-muted-foreground hover:text-foreground focus-visible:ring-ring/50 absolute top-1/2 right-0 flex size-11 -translate-y-1/2 items-center justify-center rounded-lg outline-none focus-visible:ring-3"
        data-testid={toggleTestId}
      >
        <ToggleIcon className="password-input__toggle-icon size-4" aria-hidden="true" />
        <span className="sr-only">{TOGGLE_LABEL}</span>
      </button>
    </div>
  );
}
