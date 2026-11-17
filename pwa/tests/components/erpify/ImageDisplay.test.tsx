import { afterEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { ImageDisplay } from "@/components/erpify";

const ID = "0190e2e5-5050-7050-8050-000000000001";

describe("ImageDisplay", () => {
  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it("shows the placeholder, and no <img>, when the owner holds no image", () => {
    render(<ImageDisplay imageId={null} alt="Bank logo" testId="d" />);

    expect(screen.queryByTestId("d__image")).not.toBeInTheDocument();
    expect(screen.getByTestId("d__placeholder")).toBeInTheDocument();
    expect(screen.getByTestId("d")).toHaveAttribute("data-image-state", "placeholder");
  });

  it("reads the image from the shared route keyed by the id", () => {
    vi.stubEnv("NEXT_PUBLIC_API_BASE_URL", "");
    render(<ImageDisplay imageId={ID} alt="Bank logo" testId="d" />);

    const img = screen.getByTestId("d__image");
    expect(img).toHaveAttribute("src", `/api/v1/images/${ID}`);
    expect(img).toHaveAttribute("alt", "Bank logo");
  });

  it("keeps the placeholder under the image until it has loaded, then drops it", () => {
    render(<ImageDisplay imageId={ID} alt="Bank logo" testId="d" />);
    expect(screen.getByTestId("d")).toHaveAttribute("data-image-state", "loading");
    expect(screen.getByTestId("d__placeholder")).toBeInTheDocument();

    fireEvent.load(screen.getByTestId("d__image"));

    expect(screen.getByTestId("d")).toHaveAttribute("data-image-state", "loaded");
    expect(screen.queryByTestId("d__placeholder")).not.toBeInTheDocument();
  });

  it("unmounts the <img> on a load failure, so no broken-image glyph can paint", () => {
    render(<ImageDisplay imageId={ID} alt="Bank logo" testId="d" />);

    fireEvent.error(screen.getByTestId("d__image"));

    expect(screen.queryByTestId("d__image")).not.toBeInTheDocument();
    expect(screen.getByTestId("d__placeholder")).toBeInTheDocument();
    expect(screen.getByTestId("d")).toHaveAttribute("data-image-state", "placeholder");
  });

  it("gives a replaced image, which carries a new id, a clean slate after a failure", () => {
    const { rerender } = render(<ImageDisplay imageId={ID} alt="Bank logo" testId="d" />);
    fireEvent.error(screen.getByTestId("d__image"));
    expect(screen.queryByTestId("d__image")).not.toBeInTheDocument();

    rerender(
      <ImageDisplay imageId="0190e2e5-5050-7050-8050-000000000002" alt="Bank logo" testId="d" />,
    );

    expect(screen.getByTestId("d__image")).toHaveAttribute(
      "src",
      expect.stringContaining("0190e2e5-5050-7050-8050-000000000002"),
    );
  });

  it("reads a failure that settled before React attached its handler", () => {
    // A server-rendered <img> can fail before hydration, and `error` is never replayed.
    const complete = vi.spyOn(HTMLImageElement.prototype, "complete", "get").mockReturnValue(true);
    const width = vi.spyOn(HTMLImageElement.prototype, "naturalWidth", "get").mockReturnValue(0);
    try {
      render(<ImageDisplay imageId={ID} alt="Bank logo" testId="d" />);

      expect(screen.queryByTestId("d__image")).not.toBeInTheDocument();
      expect(screen.getByTestId("d__placeholder")).toBeInTheDocument();
    } finally {
      complete.mockRestore();
      width.mockRestore();
    }
  });

  it("reads an image that finished loading before React attached its handler", () => {
    const complete = vi.spyOn(HTMLImageElement.prototype, "complete", "get").mockReturnValue(true);
    const width = vi.spyOn(HTMLImageElement.prototype, "naturalWidth", "get").mockReturnValue(32);
    try {
      render(<ImageDisplay imageId={ID} alt="Bank logo" testId="d" />);

      expect(screen.getByTestId("d")).toHaveAttribute("data-image-state", "loaded");
      expect(screen.queryByTestId("d__placeholder")).not.toBeInTheDocument();
      expect(screen.getByTestId("d__image")).toBeInTheDocument();
    } finally {
      complete.mockRestore();
      width.mockRestore();
    }
  });

  it("hides the placeholder from assistive tech while the image loads, and names it only when there is none", () => {
    render(<ImageDisplay imageId={ID} alt="Bank logo" testId="d" />);

    expect(screen.getByTestId("d__placeholder")).toHaveAttribute("aria-hidden", "true");
    expect(screen.queryByRole("img", { name: /not available/ })).not.toBeInTheDocument();

    fireEvent.error(screen.getByTestId("d__image"));

    expect(screen.getByRole("img", { name: "Bank logo (not available)" })).toBeInTheDocument();
    expect(screen.getByTestId("d__placeholder")).not.toHaveAttribute("aria-hidden");
  });

  it("lets the browser defer images it renders in numbers", () => {
    render(<ImageDisplay imageId={ID} alt="Bank logo" testId="d" />);

    expect(screen.getByTestId("d__image")).toHaveAttribute("loading", "lazy");
  });

  it("falls back to the placeholder when the composed URL is not safe", () => {
    vi.stubEnv("NEXT_PUBLIC_API_BASE_URL", "javascript:alert(1)//");
    render(<ImageDisplay imageId={ID} alt="Bank logo" testId="d" />);

    expect(screen.queryByTestId("d__image")).not.toBeInTheDocument();
  });

  it("names the placeholder when the image has a name, and hides it when decorative", () => {
    const { rerender } = render(<ImageDisplay imageId={null} alt="Bank logo" testId="d" />);
    expect(screen.getByRole("img", { name: "Bank logo (not available)" })).toBeInTheDocument();

    rerender(<ImageDisplay imageId={null} alt="" testId="d" />);

    expect(screen.queryByRole("img")).not.toBeInTheDocument();
    expect(screen.getByTestId("d__placeholder")).toHaveAttribute("aria-hidden", "true");
  });

  it("takes a custom placeholder", () => {
    render(<ImageDisplay imageId={null} alt="" placeholder={<b data-testid="custom">AB</b>} />);

    expect(screen.getByTestId("custom")).toHaveTextContent("AB");
  });
});
