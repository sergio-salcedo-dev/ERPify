"use client";

import { useCallback, useEffect, useRef, useState, type ComponentProps } from "react";
import { Eye, EyeOff } from "lucide-react";
import { Input } from "@/components/ui/input";
import { cn } from "@/components/cn";

/**
 * Static accessible name — identical in both states, so the control's name never
 * changes under assistive tech. The pressed state is carried by `aria-pressed`,
 * not by rewording the label.
 */
const TOGGLE_LABEL = "Show/hide password";

interface PasswordInputProps extends Omit<ComponentProps<"input">, "type"> {
  /** Test id for the reveal toggle button (the input keeps the spread `data-testid`). */
  toggleTestId?: string;
  /**
   * Initial reveal state. Masked by default, so a field that says nothing hides its
   * secret; the flows that want the value visible while it is typed — invitation
   * accept, password reset, recovery redeem — ask for it at the call site.
   */
  defaultRevealed?: boolean;
}

/**
 * Password field with an inline reveal toggle. A single control renders the
 * value as plain text or masked; the toggle is a >= 44px touch target with a
 * static name and `aria-pressed` state, and swaps the `Eye` / `EyeOff` icon so
 * the affordance reads without color alone. The reveal state is local UI state —
 * the typed value flows through the spread input props unchanged, so RHF keeps
 * owning it (id, aria, ref, name, change/blur all forward to the real input).
 *
 * The three text-assist attributes are the component's business rather than the
 * caller's, because only this component knows the field can become `type="text"`,
 * where a browser starts treating a secret like prose: a cloud spell checker
 * (Chrome's Enhanced Spell Check, Edge's Editor) uploads the value, and iOS
 * capitalises and autocorrects it, which corrupts the typed password silently and
 * leaves the user looking at a field that shows what they meant to type. They sit
 * ahead of the spread so a caller can still override them. `::-ms-reveal` is
 * hidden for the same ownership reason — Edge draws its own eye in this corner.
 */
export function PasswordInput({
  className,
  toggleTestId,
  defaultRevealed = false,
  ref,
  ...inputProps
}: Readonly<PasswordInputProps>) {
  const [revealed, setRevealed] = useState(defaultRevealed);
  const inputRef = useRef<HTMLInputElement | null>(null);
  const ToggleIcon = revealed ? EyeOff : Eye;

  // The caller's ref still has to reach the real input — RHF owns the field through it — so the
  // internal one is merged in rather than replacing it.
  const attachInput = useCallback(
    (node: HTMLInputElement | null) => {
      inputRef.current = node;
      if (typeof ref === "function") {
        ref(node);
      } else if (ref) {
        ref.current = node;
      }
    },
    [ref],
  );

  // A revealed value is masked again when its owning form submits — by any route, since the
  // listener is on the event rather than on a button, so Enter and `requestSubmit()` count too.
  // It is attached only while something is revealed, to the form the input belongs to then; an
  // input that changed forms mid-reveal would keep the old listener, which nothing in this tree
  // does. Local reveal state otherwise outlives the attempt: a rejected sign-in re-renders the
  // form and resets nothing, so a value revealed to check a typo stays on screen for that
  // attempt, every retry after it, and the life of the tab.
  useEffect(() => {
    if (!revealed) {
      return;
    }
    const form = inputRef.current?.form;
    if (!form) {
      return;
    }
    const remask = (): void => setRevealed(false);
    form.addEventListener("submit", remask);
    return () => form.removeEventListener("submit", remask);
  }, [revealed]);

  return (
    <div className={cn("password-input relative", className)}>
      <Input
        spellCheck={false}
        autoCorrect="off"
        autoCapitalize="none"
        {...inputProps}
        ref={attachInput}
        type={revealed ? "text" : "password"}
        className="pr-11 [&::-ms-reveal]:hidden"
      />
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
