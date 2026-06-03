"use client";

import type { ChangeEvent } from "react";
import type { ProblemViolation } from "@/context/shared/domain/ProblemDetails";
import { Input } from "@/components/ui/input";
import { FormField } from "./FormField";

export const DD_MM_YYYY_PLACEHOLDER = "dd/mm/yyyy";
export const DD_MM_YYYY_PATTERN = String.raw`\d{2}/\d{2}/\d{4}`;
export const DD_MM_YYYY_TITLE = "Date format dd/mm/yyyy, e.g. 15/04/2026";

export interface DateFieldProps {
  /** `id`/`name` shared between the FormField label and the input. */
  name: string;
  /** Visible label. The component appends a `(dd/mm/yyyy)` hint by default. */
  label: string;
  value: string;
  onChange: (next: string) => void;
  /** Pass `false` to suppress the appended `(dd/mm/yyyy)` hint after the label. */
  appendFormatHint?: boolean;
  required?: boolean;
  helper?: string;
  error?: string;
  violations?: ProblemViolation[];
  testId?: string;
  /** Placeholder; defaults to `dd/mm/yyyy`. */
  placeholder?: string;
  /** Tooltip shown on hover; defaults to a generic dd/mm/yyyy hint. */
  title?: string;
  className?: string;
  /** Forwarded to the underlying input. */
  autoComplete?: string;
}

/**
 * `DateField` is the canonical dd/mm/yyyy text input used across entities
 * (banks, customers, invoices…). It pairs with the date helpers on the
 * shared `DateTimeProvider` (`parseDdMmYyyy` /
 * `parseDdMmYyyyToStartTimestamp` / `parseDdMmYyyyToEndTimestamp`).
 *
 * The input is intentionally `<input type="text">` rather than `type="date"`
 * so the visible format is dd/mm/yyyy on every browser/locale; native date
 * pickers ignore the value's display format.
 */
export function DateField({
  name,
  label,
  value,
  onChange,
  appendFormatHint = true,
  required,
  helper,
  error,
  violations,
  testId,
  placeholder = DD_MM_YYYY_PLACEHOLDER,
  title = DD_MM_YYYY_TITLE,
  className,
  autoComplete = "off",
}: Readonly<DateFieldProps>) {
  const composedLabel = appendFormatHint ? `${label} (${DD_MM_YYYY_PLACEHOLDER})` : label;

  function handleChange(event: ChangeEvent<HTMLInputElement>): void {
    onChange(event.target.value);
  }

  return (
    <FormField
      name={name}
      label={composedLabel}
      required={required}
      helper={helper}
      error={error}
      violations={violations}
    >
      <Input
        type="text"
        inputMode="numeric"
        value={value}
        onChange={handleChange}
        placeholder={placeholder}
        pattern={DD_MM_YYYY_PATTERN}
        autoComplete={autoComplete}
        title={title}
        className={className}
        data-testid={testId}
      />
    </FormField>
  );
}
