"use client";

import type { ChangeEvent } from "react";
import type { ProblemViolation } from "@/context/shared/domain/ProblemDetails";
import { Input } from "@/components/ui/input";
import { FormField } from "./FormField";

export interface DatePickerFieldProps {
  /** `id`/`name` shared between the FormField label and the input. */
  name: string;
  /** Visible label. */
  label: string;
  /** ISO `yyyy-mm-dd` (the format the native control emits). */
  value: string;
  onChange: (next: string) => void;
  required?: boolean;
  helper?: string;
  error?: string;
  violations?: ProblemViolation[];
  testId?: string;
  className?: string;
  /** Lower bound forwarded to the native control (`yyyy-mm-dd`). */
  min?: string;
  /** Upper bound forwarded to the native control (`yyyy-mm-dd`). */
  max?: string;
  autoComplete?: string;
}

/**
 * `DatePickerField` is the canonical calendar-popup date input used across
 * entities (banks, customers, invoices, …). It renders a native HTML5
 * `<input type="date">` so the browser provides the picker; the value is
 * always ISO `yyyy-mm-dd` and pairs with
 * `DateTimeProvider.parseIsoDateToStartTimestamp` /
 * `parseIsoDateToEndTimestamp` for filter ranges.
 *
 * Use `DateField` instead when you need the dd/mm/yyyy text-typing
 * variant (e.g. timestamp-style display fields).
 */
export function DatePickerField({
  name,
  label,
  value,
  onChange,
  required,
  helper,
  error,
  violations,
  testId,
  className,
  min,
  max,
  autoComplete = "off",
}: Readonly<DatePickerFieldProps>) {
  function handleChange(event: ChangeEvent<HTMLInputElement>): void {
    onChange(event.target.value);
  }

  return (
    <FormField
      name={name}
      label={label}
      required={required}
      helper={helper}
      error={error}
      violations={violations}
    >
      <Input
        type="date"
        value={value}
        onChange={handleChange}
        min={min}
        max={max}
        autoComplete={autoComplete}
        className={className}
        data-testid={testId}
      />
    </FormField>
  );
}
