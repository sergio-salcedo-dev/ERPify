/**
 * Severity levels shared by every notification channel (toast, and future
 * banner / push). Kept as a const object + union so adding a level does not
 * change channel interfaces, mirroring the project's other domain enums.
 */
export const NotificationLevel = {
  Success: "success",
  Error: "error",
  Info: "info",
  Warning: "warning",
} as const;

export type NotificationLevel = (typeof NotificationLevel)[keyof typeof NotificationLevel];
