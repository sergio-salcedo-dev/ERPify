"use client";

import { useState } from "react";
import { ImageUploadField } from "@/components/erpify";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import {
  IMAGE_UPLOADER_KEY,
  type ImageUploader,
} from "@/context/shared/images/domain/ImageUploader";

/**
 * The owner's write URL. Deliberately outside `/api/`: it is not a route of the API, and an e2e
 * fulfils it with `page.route`. A consumer passes the real route of its own resource instead.
 */
export const STUB_IMAGE_WRITE_URL = "/dev-tools-stub/image";

/** Client island: resolves the port and holds the one id the stub "owner" has. */
export function ImageUploadHarness() {
  const [imageId, setImageId] = useState<string | null>(null);
  const uploader = container.get<ImageUploader>(IMAGE_UPLOADER_KEY);

  return (
    <section className="image-upload-harness flex max-w-xl flex-col gap-4 p-6">
      <h1 className="text-xl font-semibold">Image upload harness</h1>
      <p className="text-muted-foreground text-sm">
        The control below writes to a stub URL ({STUB_IMAGE_WRITE_URL}); nothing is stored by the
        API.
      </p>
      <ImageUploadField
        imageId={imageId}
        onImageChange={setImageId}
        onUpload={(file) => uploader.upload(STUB_IMAGE_WRITE_URL, file)}
        onRemove={() => uploader.remove(STUB_IMAGE_WRITE_URL)}
        label="Harness image"
        testIdPrefix="image-upload-harness"
      />
    </section>
  );
}
