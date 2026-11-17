import { describe, expect, it } from "vitest";
import { checkImageFile, ImageFileRefusal } from "@/context/shared/images/domain/checkImageFile";
import {
  ACCEPTED_IMAGE_TYPES,
  IMAGE_ACCEPT_ATTRIBUTE,
  MAX_IMAGE_BYTES,
} from "@/context/shared/images/domain/imageLimits";

describe("checkImageFile", () => {
  it.each(ACCEPTED_IMAGE_TYPES)("admits a non-empty %s under the cap", (type) => {
    expect(checkImageFile({ size: 1024, type })).toEqual({ ok: true });
  });

  it("admits a file of exactly the cap", () => {
    expect(checkImageFile({ size: MAX_IMAGE_BYTES, type: "image/png" })).toEqual({ ok: true });
  });

  it("refuses an empty file, naming the reason, before it looks at the type", () => {
    const check = checkImageFile({ size: 0, type: "application/pdf" });

    expect(check).toMatchObject({ ok: false, reason: ImageFileRefusal.EMPTY });
  });

  it("refuses a file one byte over the cap", () => {
    const check = checkImageFile({ size: MAX_IMAGE_BYTES + 1, type: "image/png" });

    expect(check).toMatchObject({ ok: false, reason: ImageFileRefusal.TOO_LARGE });
    expect(check.ok === false && check.message).toContain("20 MB");
  });

  it.each([
    "image/svg+xml",
    "image/jpg",
    "image/*",
    "IMAGE/PNG",
    "image/png; charset=utf-8",
    "application/octet-stream",
    "",
  ])("refuses the type %j, which is not byte for byte on the allowlist", (type) => {
    expect(checkImageFile({ size: 10, type })).toMatchObject({
      ok: false,
      reason: ImageFileRefusal.UNSUPPORTED_TYPE,
    });
  });

  it("offers the picker exactly the allowlist", () => {
    expect(IMAGE_ACCEPT_ATTRIBUTE).toBe("image/jpeg,image/png,image/webp,image/gif");
  });
});
