/**
 * Shared sorting constants for the PWA.
 *
 * {@link SortDirection} - The direction of a sort (ASC / DESC).
 */

export const SortDirection = {
  ASC: "asc",
  DESC: "desc",
} as const;

export type SortDirection = (typeof SortDirection)[keyof typeof SortDirection];
