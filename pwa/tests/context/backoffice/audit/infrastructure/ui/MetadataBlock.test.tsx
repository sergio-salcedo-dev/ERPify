import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { MetadataBlock } from "@/context/backoffice/audit/infrastructure/ui/MetadataBlock";

describe("MetadataBlock", () => {
  it("reads null or an empty object as «No metadata», not an empty block", () => {
    const { rerender } = render(<MetadataBlock value={null} />);
    expect(screen.getByText("No metadata")).toBeInTheDocument();
    rerender(<MetadataBlock value={{}} />);
    expect(screen.getByText("No metadata")).toBeInTheDocument();
  });

  it("renders the JSON as escaped text with a copy-raw control", () => {
    render(<MetadataBlock value={{ route: "/api/v1/backoffice/banks", method: "GET" }} />);
    expect(screen.getByText(/"route": "\/api\/v1\/backoffice\/banks"/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /copy metadata/i })).toBeInTheDocument();
  });

  it("truncates a pathological object but keeps the full raw JSON copyable", () => {
    const big = { blob: "x".repeat(6000) };
    render(<MetadataBlock value={big} />);
    expect(screen.getByText(/Metadata truncated/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /copy metadata/i })).toBeInTheDocument();
  });
});
