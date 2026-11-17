import { describe, expect, it } from "vitest";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { ApiImageUploader } from "@/context/shared/images/infrastructure/ApiImageUploader";
import {
  IMAGE_UPLOADER_KEY,
  type ImageUploader,
} from "@/context/shared/images/domain/ImageUploader";

describe("ImageUploader binding", () => {
  it("resolves the HTTP adapter", () => {
    expect(container.get<ImageUploader>(IMAGE_UPLOADER_KEY)).toBeInstanceOf(ApiImageUploader);
  });

  it("resolves a singleton", () => {
    expect(container.get<ImageUploader>(IMAGE_UPLOADER_KEY)).toBe(
      container.get<ImageUploader>(IMAGE_UPLOADER_KEY),
    );
  });
});
