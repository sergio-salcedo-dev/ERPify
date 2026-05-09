import type { DateTimeProvider } from "../../domain/DateTimeProvider/DateTimeProvider";
import { DateFnsDateTimeProvider } from "./DateFnsDateTimeProvider";

/**
 * Default {@link DateTimeProvider} instance used by the application.
 *
 * Consumers MUST type the import as `DateTimeProvider` (the interface), not
 * as `DateFnsDateTimeProvider`. This keeps the rest of the codebase free of
 * concrete-adapter coupling so the implementation can be swapped.
 */
export const dateTimeProvider: DateTimeProvider = new DateFnsDateTimeProvider();

export type { DateTimeProvider } from "../../domain/DateTimeProvider/DateTimeProvider";
export type { AddUnit, DurationUnit } from "../../domain/DateTimeProvider/DateTimeProvider";
