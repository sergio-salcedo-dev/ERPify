import { afterEach, describe, expect, it, vi } from "vitest";

const headersMock = vi.fn();
vi.mock("next/headers", () => ({ headers: () => headersMock() }));

import {
  E2E_REQUEST_HEADER,
  isAutomatedTestRequest,
} from "@/context/shared/dev-tools/infrastructure/isAutomatedTestRequest";

afterEach(() => {
  vi.clearAllMocks();
});

describe("isAutomatedTestRequest", () => {
  it("is true when the e2e marker header is present", async () => {
    headersMock.mockResolvedValue(new Headers({ [E2E_REQUEST_HEADER]: "1" }));
    expect(await isAutomatedTestRequest()).toBe(true);
  });

  it("is false when the header is absent (real user / manual dev)", async () => {
    headersMock.mockResolvedValue(new Headers());
    expect(await isAutomatedTestRequest()).toBe(false);
  });
});
