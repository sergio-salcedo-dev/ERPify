import { describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { ImageUploadField } from "@/components/erpify";
import { NETWORK_ERROR, REQUEST_TIMEOUT } from "@/context/shared/http-client/domain/HttpClient";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { MAX_IMAGE_BYTES } from "@/context/shared/images/domain/imageLimits";

const PREFIX = "up";

function problem(type: string, status: number, extra: Record<string, unknown> = {}): HttpError {
  return new HttpError({
    type,
    title: "Server title",
    status,
    instance: "01H-instance",
    "correlation-id": "01H-correlation",
    ...extra,
  });
}

function png(name = "logo.png", bytes = 8): File {
  return new File([new Uint8Array(bytes)], name, { type: "image/png" });
}

function setup(overrides: Partial<Parameters<typeof ImageUploadField>[0]> = {}) {
  const onUpload = vi.fn().mockResolvedValue({ imageId: "img-new" });
  const onRemove = vi.fn().mockResolvedValue(undefined);
  const onImageChange = vi.fn();
  const view = render(
    <ImageUploadField
      imageId={null}
      onImageChange={onImageChange}
      onUpload={onUpload}
      onRemove={onRemove}
      label="Bank logo"
      testIdPrefix={PREFIX}
      {...overrides}
    />,
  );
  return { ...view, onUpload, onRemove, onImageChange };
}

function pick(file: File): void {
  fireEvent.change(screen.getByTestId(`${PREFIX}__input`), { target: { files: [file] } });
}

describe("ImageUploadField", () => {
  it("offers the allowlist to the picker and nothing to remove when no image is held", () => {
    setup();

    expect(screen.getByTestId(`${PREFIX}__input`)).toHaveAttribute(
      "accept",
      "image/jpeg,image/png,image/webp,image/gif",
    );
    expect(screen.getByTestId(`${PREFIX}__pick`)).toHaveTextContent("Upload bank logo");
    expect(screen.queryByTestId(`${PREFIX}__remove`)).not.toBeInTheDocument();
  });

  it("offers replace and remove when an image is held", () => {
    setup({ imageId: "img-1" });

    expect(screen.getByTestId(`${PREFIX}__pick`)).toHaveTextContent("Replace bank logo");
    expect(screen.getByTestId(`${PREFIX}__remove`)).toBeInTheDocument();
  });

  it("uploads a valid file and reports the id the owner now holds", async () => {
    const { onUpload, onImageChange } = setup();
    const file = png();

    pick(file);

    await waitFor(() => expect(onImageChange).toHaveBeenCalledWith("img-new"));
    expect(onUpload).toHaveBeenCalledWith(file);
  });

  it("removes the image and reports that none is held", async () => {
    const { onRemove, onImageChange } = setup({ imageId: "img-1" });

    fireEvent.click(screen.getByTestId(`${PREFIX}__remove`));

    await waitFor(() => expect(onImageChange).toHaveBeenCalledWith(null));
    expect(onRemove).toHaveBeenCalledTimes(1);
  });

  it.each([
    ["an empty file", new File([], "e.png", { type: "image/png" }), "empty"],
    [
      "a type outside the allowlist",
      new File([new Uint8Array(4)], "a.svg", { type: "image/svg+xml" }),
      "not a JPEG",
    ],
    [
      "a file over the cap",
      Object.defineProperty(png("big.png"), "size", { value: MAX_IMAGE_BYTES + 1 }),
      "larger than 20 MB",
    ],
  ])("sends nothing for %s and says why, with the field still usable", (_label, file, text) => {
    const { onUpload } = setup();

    pick(file as File);

    expect(onUpload).not.toHaveBeenCalled();
    expect(screen.getByTestId(`${PREFIX}__refusal`)).toHaveTextContent(text);
    expect(screen.getByTestId(`${PREFIX}__pick`)).toBeEnabled();
  });

  it("clears a precheck refusal when a valid file follows", async () => {
    setup();
    pick(new File([], "e.png", { type: "image/png" }));
    expect(screen.getByTestId(`${PREFIX}__refusal`)).toBeInTheDocument();

    pick(png());

    await waitFor(() => expect(screen.queryByTestId(`${PREFIX}__refusal`)).not.toBeInTheDocument());
  });

  it.each([
    ["image-length-required", 411, "cut short"],
    ["image-too-large", 413, "too large"],
    ["image-unsupported-type", 415, "supported image format"],
    ["image-invalid", 422, "could not be read"],
  ])(
    "shows a persistent, own message for %s and names the file kept",
    async (type, status, text) => {
      const { onImageChange } = setup({
        onUpload: vi.fn().mockRejectedValue(problem(type, status)),
      });

      pick(png("holder-logo.png"));

      const error = await screen.findByTestId(`${PREFIX}__error`);
      expect(error).toHaveTextContent(text);
      expect(error).toHaveTextContent("holder-logo.png");
      expect(error).not.toHaveTextContent("Server title");
      expect(onImageChange).not.toHaveBeenCalled();
      expect(screen.getByTestId(`${PREFIX}__pick`)).toBeEnabled();
    },
  );

  it("states the wait of a throttled write", async () => {
    setup({
      onUpload: vi.fn().mockRejectedValue(problem("rate-limited", 429, { retryAfterSeconds: 12 })),
    });

    pick(png());

    expect(await screen.findByTestId(`${PREFIX}__error`)).toHaveTextContent("Wait 12 seconds");
  });

  it("retries the kept file, and clears the error on success", async () => {
    const onUpload = vi
      .fn()
      .mockRejectedValueOnce(problem("image-too-large", 413))
      .mockResolvedValueOnce({ imageId: "img-new" });
    const { onImageChange } = setup({ onUpload });
    const file = png();
    pick(file);
    await screen.findByTestId(`${PREFIX}__error`);

    fireEvent.click(screen.getByTestId(`${PREFIX}__retry`));

    await waitFor(() => expect(onImageChange).toHaveBeenCalledWith("img-new"));
    expect(onUpload).toHaveBeenLastCalledWith(file);
    expect(screen.queryByTestId(`${PREFIX}__error`)).not.toBeInTheDocument();
  });

  it("shows a failed removal as a persistent error, keeps the image and offers a retry", async () => {
    const { onImageChange } = setup({
      imageId: "img-1",
      onRemove: vi.fn().mockRejectedValue(problem("bank-not-found", 404)),
    });

    fireEvent.click(screen.getByTestId(`${PREFIX}__remove`));

    const error = await screen.findByTestId(`${PREFIX}__error`);
    expect(error).toHaveTextContent("no longer exists");
    expect(error).toHaveTextContent("not removed");
    expect(onImageChange).not.toHaveBeenCalled();
    expect(screen.getByTestId(`${PREFIX}__retry`)).toBeInTheDocument();
  });

  it("turns a non-HTTP failure into the unknown fallback rather than throwing", async () => {
    setup({ onUpload: vi.fn().mockRejectedValue(new Error("boom")) });

    pick(png());

    expect(await screen.findByTestId(`${PREFIX}__error`)).toHaveTextContent("could not be saved");
  });

  it("ignores a second pick while one is in flight and shows busy", async () => {
    let finish: (value: { imageId: string }) => void = () => {};
    const onUpload = vi.fn().mockImplementation(
      () =>
        new Promise((resolve) => {
          finish = resolve;
        }),
    );
    const { onImageChange } = setup({ onUpload });

    pick(png("first.png"));
    await screen.findByTestId(`${PREFIX}__busy`);
    pick(png("second.png"));

    expect(onUpload).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId(PREFIX)).toHaveAttribute("aria-busy", "true");
    expect(screen.getByTestId(`${PREFIX}__pick`)).toBeDisabled();

    await act(async () => {
      finish({ imageId: "img-new" });
    });

    await waitFor(() => expect(onImageChange).toHaveBeenCalledWith("img-new"));
    expect(screen.queryByTestId(`${PREFIX}__busy`)).not.toBeInTheDocument();
    expect(screen.getByTestId(`${PREFIX}__pick`)).toBeEnabled();
  });

  it("disables every action when told the caller may not change the image", () => {
    setup({ imageId: "img-1", disabled: true });

    expect(screen.getByTestId(`${PREFIX}__pick`)).toBeDisabled();
    expect(screen.getByTestId(`${PREFIX}__remove`)).toBeDisabled();
  });

  it("opens the file picker when the visible button is clicked", () => {
    setup();
    const click = vi.spyOn(screen.getByTestId(`${PREFIX}__input`), "click");

    fireEvent.click(screen.getByTestId(`${PREFIX}__pick`));

    expect(click).toHaveBeenCalledTimes(1);
  });

  it.each([
    [NETWORK_ERROR, 0],
    [REQUEST_TIMEOUT, 0],
  ])(
    "says an upload that ended in %s has an unknown outcome and to reload",
    async (type, status) => {
      setup({ onUpload: vi.fn().mockRejectedValue(problem(type, status)) });

      pick(png("holder.png"));

      const error = await screen.findByTestId(`${PREFIX}__error`);
      expect(error).toHaveTextContent("not known whether");
      expect(error).toHaveTextContent("Reload to see the image on file");
      expect(error).not.toHaveTextContent("is unchanged");
    },
  );

  it("says a removal that ended in a timeout has an unknown outcome and to reload", async () => {
    setup({
      imageId: "img-1",
      onRemove: vi.fn().mockRejectedValue(problem(REQUEST_TIMEOUT, 0)),
    });

    fireEvent.click(screen.getByTestId(`${PREFIX}__remove`));

    const error = await screen.findByTestId(`${PREFIX}__error`);
    expect(error).toHaveTextContent("not known whether the image was removed");
    expect(error).toHaveTextContent("Reload to see the image on file");
  });

  it("titles a failed removal with the removal fallback, a failed upload with the save one", async () => {
    setup({
      imageId: "img-1",
      onRemove: vi.fn().mockRejectedValue(problem("something-new", 500)),
      onUpload: vi.fn().mockRejectedValue(problem("something-new", 500)),
    });

    fireEvent.click(screen.getByTestId(`${PREFIX}__remove`));
    expect(await screen.findByTestId(`${PREFIX}__error`)).toHaveTextContent(
      "The image could not be removed. Try again.",
    );

    pick(png());
    await waitFor(() =>
      expect(screen.getByTestId(`${PREFIX}__error`)).toHaveTextContent(
        "The image could not be saved. Try again.",
      ),
    );
  });

  it("gives the action buttons a short static name and a descriptive title", () => {
    setup({ imageId: "img-1" });

    expect(screen.getByTestId(`${PREFIX}__pick`)).toHaveAttribute("aria-label", "Replace");
    expect(screen.getByTestId(`${PREFIX}__pick`)).toHaveAttribute("title", "Replace bank logo");
    expect(screen.getByTestId(`${PREFIX}__remove`)).toHaveAttribute("aria-label", "Remove");
    expect(screen.getByTestId(`${PREFIX}__remove`)).toHaveAttribute("title", "Remove bank logo");
  });

  it("does not take focus back from another element when it re-renders while an error shows", async () => {
    const props = {
      imageId: null,
      onImageChange: vi.fn(),
      onUpload: vi.fn().mockRejectedValue(problem("image-too-large", 413)),
      onRemove: vi.fn(),
      label: "Bank logo",
      testIdPrefix: PREFIX,
    };
    const { rerender } = render(
      <>
        <button type="button" data-testid="elsewhere">
          elsewhere
        </button>
        <ImageUploadField {...props} />
      </>,
    );
    pick(png());
    await screen.findByTestId(`${PREFIX}__error`);
    screen.getByTestId("elsewhere").focus();
    expect(screen.getByTestId("elsewhere")).toHaveFocus();

    rerender(
      <>
        <button type="button" data-testid="elsewhere">
          elsewhere
        </button>
        <ImageUploadField {...props} />
      </>,
    );

    expect(screen.getByTestId("elsewhere")).toHaveFocus();
  });
});
