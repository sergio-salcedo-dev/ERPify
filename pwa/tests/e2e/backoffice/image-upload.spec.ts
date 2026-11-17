import type { Route } from "@playwright/test";
import { test, expect, type Page } from "../fixtures/authenticatedTest";
import { VIEWPORT_DESKTOP } from "../constants";

/**
 * The shared image control, driven through the dev-tools harness against a stubbed write route
 * and a stubbed read route: set, replace, remove, and the refusals the control maps. The harness
 * writes to `/dev-tools-stub/image` (not an API route) and the `<img>` reads
 * `/api/v1/images/{id}`; both are fulfilled here, so no image consumer needs to exist.
 */
const HARNESS_URL = "/backoffice/dev-tools/image-upload";
const WRITE_URL = "**/dev-tools-stub/image";
const READ_URL = "**/api/v1/images/**";
const PREFIX = "image-upload-harness";

// A valid 1x1 PNG.
const PNG = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==",
  "base64",
);

function file(name = "logo.png", buffer: Buffer = PNG) {
  return { name, mimeType: "image/png", buffer };
}

function problem(type: string, status: number, title: string, extra = {}) {
  return {
    ...extra,
    type,
    title,
    status,
    instance: "01H-image-instance",
    "correlation-id": "01H-image-correlation",
  };
}

interface Stub {
  writes: Array<{
    method: string;
    contentType: string | undefined;
    bytes: number;
  }>;
}

/** Answers every write with the next scripted outcome, and every read with the PNG. */
async function stubImageApi(
  page: Page,
  outcomes: Array<(route: Route) => Promise<void>>,
): Promise<Stub> {
  const stub: Stub = { writes: [] };
  let call = 0;
  await page.route(WRITE_URL, async (route) => {
    const request = route.request();
    stub.writes.push({
      method: request.method(),
      contentType: request.headers()["content-type"],
      bytes: request.postDataBuffer()?.length ?? 0,
    });
    const outcome = outcomes[Math.min(call, outcomes.length - 1)];
    call += 1;
    await outcome(route);
  });
  await page.route(READ_URL, (route) =>
    route.fulfill({ status: 200, contentType: "image/png", body: PNG }),
  );
  return stub;
}

const stored = (imageId: string) => (route: Route) =>
  route.fulfill({
    status: 200,
    contentType: "application/json",
    body: JSON.stringify({ imageId }),
  });

const removed = (route: Route) => route.fulfill({ status: 204 });

const refused = (status: number, type: string, title: string) => (route: Route) =>
  route.fulfill({
    status,
    contentType: "application/problem+json",
    body: JSON.stringify(problem(type, status, title)),
  });

test.describe("Image upload control (dev-tools harness)", () => {
  test.use({ viewport: VIEWPORT_DESKTOP });

  test("sets, replaces and removes an image", async ({ page }) => {
    const stub = await stubImageApi(page, [
      stored("0190e2e5-5050-7050-8050-0000000000a1"),
      stored("0190e2e5-5050-7050-8050-0000000000a2"),
      removed,
    ]);
    await page.goto(HARNESS_URL);
    const preview = page.getByTestId(`${PREFIX}__preview`);
    await expect(preview).toHaveAttribute("data-image-state", "placeholder");
    await expect(page.getByTestId(`${PREFIX}__remove`)).toHaveCount(0);

    // Set: the raw bytes go out (`Content-Length` is added by the browser below the layer a stubbed
    // route can see, so the body's own length is what is asserted here) as the body, typed by the file, and the display shows the image.
    await page.getByTestId(`${PREFIX}__input`).setInputFiles(file());
    await expect(preview).toHaveAttribute("data-image-state", "loaded");
    await expect(page.getByTestId(`${PREFIX}__preview__image`)).toHaveAttribute(
      "src",
      /\/api\/v1\/images\/0190e2e5-5050-7050-8050-0000000000a1$/,
    );
    expect(stub.writes[0]).toEqual({
      method: "PUT",
      contentType: "image/png",
      bytes: PNG.length,
    });
    await expect(page.getByTestId(`${PREFIX}__pick`)).toContainText("Replace");

    // Replace: same call, and the display re-keys to the new id.
    await page.getByTestId(`${PREFIX}__input`).setInputFiles(file("second.png"));
    await expect(page.getByTestId(`${PREFIX}__preview__image`)).toHaveAttribute(
      "src",
      /0190e2e5-5050-7050-8050-0000000000a2$/,
    );
    expect(stub.writes[1].method).toBe("PUT");

    // Remove: a DELETE, then the placeholder and no remove offered.
    await page.getByTestId(`${PREFIX}__remove`).click();
    await expect(preview).toHaveAttribute("data-image-state", "placeholder");
    await expect(page.getByTestId(`${PREFIX}__remove`)).toHaveCount(0);
    expect(stub.writes[2].method).toBe("DELETE");
  });

  test("sends nothing for a file the check refuses, and stays usable", async ({ page }) => {
    const stub = await stubImageApi(page, [stored("0190e2e5-5050-7050-8050-0000000000a1")]);
    await page.goto(HARNESS_URL);

    await page
      .getByTestId(`${PREFIX}__input`)
      .setInputFiles({ name: "empty.png", mimeType: "image/png", buffer: Buffer.alloc(0) });

    await expect(page.getByTestId(`${PREFIX}__refusal`)).toContainText("empty");
    await page
      .getByTestId(`${PREFIX}__input`)
      .setInputFiles({ name: "x.svg", mimeType: "image/svg+xml", buffer: Buffer.from("<svg/>") });
    await expect(page.getByTestId(`${PREFIX}__refusal`)).toContainText("not a JPEG");
    expect(stub.writes).toHaveLength(0);

    await page.getByTestId(`${PREFIX}__input`).setInputFiles(file());
    await expect(page.getByTestId(`${PREFIX}__preview`)).toHaveAttribute(
      "data-image-state",
      "loaded",
    );
    await expect(page.getByTestId(`${PREFIX}__refusal`)).toHaveCount(0);
  });

  test("keeps a server refusal on screen, names the file, and retries it", async ({ page }) => {
    const stub = await stubImageApi(page, [
      refused(413, "image-too-large", "Payload too large."),
      stored("0190e2e5-5050-7050-8050-0000000000a1"),
    ]);
    await page.goto(HARNESS_URL);

    await page.getByTestId(`${PREFIX}__input`).setInputFiles(file("holder.png"));

    const error = page.getByTestId(`${PREFIX}__error`);
    await expect(error).toContainText("too large");
    await expect(error).toContainText("holder.png");
    await expect(page.getByTestId(`${PREFIX}__preview`)).toHaveAttribute(
      "data-image-state",
      "placeholder",
    );

    await page.getByTestId(`${PREFIX}__retry`).click();

    await expect(error).toHaveCount(0);
    await expect(page.getByTestId(`${PREFIX}__preview`)).toHaveAttribute(
      "data-image-state",
      "loaded",
    );
    expect(stub.writes).toHaveLength(2);
    expect(stub.writes[1].bytes).toBe(PNG.length);
  });

  test("maps a throttled write to the wait the server asked for", async ({ page }) => {
    await stubImageApi(page, [
      (route) =>
        route.fulfill({
          status: 429,
          contentType: "application/problem+json",
          headers: { "Retry-After": "23" },
          body: JSON.stringify(
            problem("rate-limited", 429, "Too many requests.", { retryAfterSeconds: 23 }),
          ),
        }),
    ]);
    await page.goto(HARNESS_URL);

    await page.getByTestId(`${PREFIX}__input`).setInputFiles(file());

    await expect(page.getByTestId(`${PREFIX}__error`)).toContainText("Wait 23 seconds");
  });

  test("shows the placeholder, not a broken image, when the image will not load", async ({
    page,
  }) => {
    await stubImageApi(page, [stored("0190e2e5-5050-7050-8050-0000000000a1")]);
    await page.unroute(READ_URL);
    await page.route(READ_URL, (route) =>
      route.fulfill({
        status: 404,
        contentType: "application/problem+json",
        body: JSON.stringify(problem("image-not-found", 404, "Not found.")),
      }),
    );
    await page.goto(HARNESS_URL);

    await page.getByTestId(`${PREFIX}__input`).setInputFiles(file());

    const preview = page.getByTestId(`${PREFIX}__preview`);
    await expect(preview).toHaveAttribute("data-image-state", "placeholder");
    await expect(page.getByTestId(`${PREFIX}__preview__image`)).toHaveCount(0);
    await expect(page.getByTestId(`${PREFIX}__preview__placeholder`)).toBeVisible();
  });
});
