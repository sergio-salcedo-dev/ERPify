import { beforeEach, describe, expect, it, vi } from "vitest";
import { render } from "@testing-library/react";
import type { ToasterProps } from "sonner";
import { SonnerToaster } from "@/context/shared/Notification/infrastructure/Toast/SonnerToaster";

const { toasterSpy } = vi.hoisted(() => ({
  toasterSpy: vi.fn((_props: unknown) => null),
}));

vi.mock("sonner", () => ({
  Toaster: toasterSpy,
}));

function renderedToasterProps(): ToasterProps {
  expect(toasterSpy).toHaveBeenCalledTimes(1);
  return toasterSpy.mock.calls[0]?.[0] as ToasterProps;
}

describe("SonnerToaster", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("clamps the toast description to two lines by default", () => {
    render(<SonnerToaster />);
    const props = renderedToasterProps();
    expect(props.toastOptions?.classNames?.description).toBe("line-clamp-2 break-words");
  });

  it("keeps the description clamp when a caller passes its own toastOptions", () => {
    render(
      <SonnerToaster toastOptions={{ duration: 1000, classNames: { toast: "caller-toast" } }} />,
    );
    const props = renderedToasterProps();
    expect(props.toastOptions?.duration).toBe(1000);
    expect(props.toastOptions?.classNames?.toast).toBe("caller-toast");
    expect(props.toastOptions?.classNames?.description).toBe("line-clamp-2 break-words");
  });

  it("lets an explicit caller classNames.description override the clamp", () => {
    render(<SonnerToaster toastOptions={{ classNames: { description: "caller-description" } }} />);
    const props = renderedToasterProps();
    expect(props.toastOptions?.classNames?.description).toBe("caller-description");
  });
});
