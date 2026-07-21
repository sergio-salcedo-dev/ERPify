import { describe, expect, it, vi } from "vitest";
import { FulfilIdentityErasure } from "@/context/backoffice/user/application/FulfilIdentityErasure";
import type { EraseIdentityRepository } from "@/context/backoffice/user/domain/EraseIdentityRepository";

const USER_ID = "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c";

describe("FulfilIdentityErasure", () => {
  it("delegates the erasure to the repository", async () => {
    const erase = vi.fn().mockResolvedValue(undefined);
    const repository: EraseIdentityRepository = { erase };

    await new FulfilIdentityErasure(repository).run(USER_ID);

    expect(erase).toHaveBeenCalledWith(USER_ID);
  });
});
