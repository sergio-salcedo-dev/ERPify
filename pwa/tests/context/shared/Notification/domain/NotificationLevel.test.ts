import { describe, expect, it } from "vitest";
import { NotificationLevel } from "@/context/shared/Notification/domain/NotificationLevel";

describe("NotificationLevel", () => {
  it("enumerates the four supported levels", () => {
    expect(Object.values(NotificationLevel)).toEqual(["success", "error", "info", "warning"]);
  });
});
