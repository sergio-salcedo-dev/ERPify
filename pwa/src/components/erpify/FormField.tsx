"use client";

import {
  Children,
  cloneElement,
  createContext,
  isValidElement,
  useContext,
  useId,
  useMemo,
  type ReactElement,
  type ReactNode,
} from "react";
import type { ProblemViolation } from "@/context/shared/error/domain/ProblemDetails";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";

interface FormFieldContextValue {
  id: string;
  errorId?: string;
  helperId?: string;
  invalid: boolean;
}

const FormFieldContext = createContext<FormFieldContextValue | null>(null);

/**
 * Hook for inputs nested inside <FormField>. Use when the input cannot be a
 * direct child (e.g. a complex composite). For the simple case, FormField
 * auto-clones a single child input and injects id/aria props.
 */
export function useFormField(): FormFieldContextValue {
  const ctx = useContext(FormFieldContext);
  if (!ctx) {
    throw new Error("useFormField must be used inside <FormField>");
  }
  return ctx;
}

interface FormFieldProps {
  /** Field name. Used to match against violations[].field. */
  name: string;
  label: string;
  /** A single input element (or composite). FormField clones it and injects id + aria props. */
  children: ReactNode;
  required?: boolean;
  helper?: string;
  /** Direct error message — typically from RHF formState.errors[name]?.message. */
  error?: string;
  /** RFC 9457 violations — the entry where field === name is used as fallback. */
  violations?: ProblemViolation[];
  /** Layout direction. */
  layout?: "stacked" | "inline";
  className?: string;
}

export function FormField({
  name,
  label,
  children,
  required,
  helper,
  error,
  violations,
  layout = "stacked",
  className,
}: Readonly<FormFieldProps>) {
  const baseId = useId();
  const id = `field-${name}-${baseId}`;
  const helperId = helper ? `${id}-helper` : undefined;

  const resolvedError = error ?? violations?.find((v) => v.field === name)?.message;
  const errorId = resolvedError ? `${id}-error` : undefined;
  const invalid = Boolean(resolvedError);

  const describedBy = [helperId, errorId].filter(Boolean).join(" ") || undefined;

  // Clone the single child to inject id + aria attributes. If consumer needs more
  // control, they can use the useFormField() hook on a custom input.
  let child: ReactNode = children;
  try {
    const only = Children.only(children);
    if (isValidElement(only)) {
      const childProps = (only.props ?? {}) as Record<string, unknown>;
      child = cloneElement(only as ReactElement<Record<string, unknown>>, {
        id,
        "aria-invalid": invalid || undefined,
        "aria-describedby": describedBy,
        "aria-required": required || undefined,
        ...childProps,
      });
    }
  } catch {
    // Multiple children — leave unchanged. Consumer must wire props via useFormField().
  }

  const contextValue = useMemo(
    () => ({ id, errorId, helperId, invalid }),
    [id, errorId, helperId, invalid],
  );

  return (
    <FormFieldContext.Provider value={contextValue}>
      <div
        data-form-field={name}
        className={cn(
          "flex gap-1.5",
          layout === "stacked" ? "flex-col" : "flex-row items-center gap-3",
          className,
        )}
      >
        <Label htmlFor={id} className={layout === "inline" ? "min-w-32 shrink-0" : undefined}>
          {label}
          {required ? (
            <span aria-hidden="true" className="text-destructive">
              *
            </span>
          ) : null}
          {required ? <span className="sr-only"> (required)</span> : null}
        </Label>
        <div className={layout === "inline" ? "flex-1" : undefined}>
          {child}
          {helper ? (
            <p id={helperId} className="text-muted-foreground mt-1 text-xs">
              {helper}
            </p>
          ) : null}
          {resolvedError ? (
            <p
              id={errorId}
              role="alert"
              aria-live="polite"
              className="text-destructive mt-1 text-xs"
            >
              {resolvedError}
            </p>
          ) : null}
        </div>
      </div>
    </FormFieldContext.Provider>
  );
}
