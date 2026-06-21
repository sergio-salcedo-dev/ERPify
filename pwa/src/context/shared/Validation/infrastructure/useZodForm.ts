"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import {
  useForm,
  type DefaultValues,
  type FieldValues,
  type Resolver,
  type UseFormProps,
  type UseFormReturn,
} from "react-hook-form";
import type { ZodType } from "zod";

/**
 * `useZodForm` is the canonical bridge between a bounded context's Zod
 * schema (e.g. `BankSchema`) and React Hook Form. It exists so that React
 * components stay decoupled from the validation library: the component
 * imports the schema and the inferred TS type from the entity's
 * `application/schemas/` folder, then passes the schema into this hook —
 * it never imports `zod` or `@hookform/resolvers/zod` directly.
 *
 * @example
 * ```tsx
 * import { BankSchema, type BankFormValues } from "@/context/.../schemas/BankSchema";
 * import { useZodForm } from "@/context/shared/Validation/infrastructure";
 *
 * const form = useZodForm<BankFormValues>(BankSchema, {
 *   defaultValues: { name: "", shortName: "" },
 * });
 * ```
 *
 * Implementation note: zod 4's `ZodType<T>` and the latest
 * `@hookform/resolvers/zod` use slightly different generic shapes for the
 * "input" type, so we cast the schema once at this boundary. The contract
 * with callers is preserved by the explicit `UseFormReturn<TValues>`
 * return type.
 */
export function useZodForm<TValues extends FieldValues>(
  schema: ZodType<TValues>,
  options?: Omit<UseFormProps<TValues>, "resolver"> & { defaultValues?: DefaultValues<TValues> },
): UseFormReturn<TValues> {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const resolver = zodResolver(schema as any) as unknown as Resolver<TValues>;
  return useForm<TValues>({
    mode: "onSubmit",
    reValidateMode: "onChange",
    ...options,
    resolver,
  });
}
