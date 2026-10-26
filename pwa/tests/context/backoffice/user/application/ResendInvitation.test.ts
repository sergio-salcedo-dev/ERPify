import { describe, expect, it, vi } from "vitest";
import { ResendInvitation } from "@/context/backoffice/user/application/ResendInvitation";
import type { ResendInvitationRepository } from "@/context/backoffice/user/domain/ResendInvitationRepository";

describe("ResendInvitation", () => {
  it("delegates the resend to the repository, keyed by the invited user's id", async () => {
    const resend = vi.fn().mockResolvedValue(undefined);
    const repository: ResendInvitationRepository = { resend };

    await expect(
      new ResendInvitation(repository).run("0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c"),
    ).resolves.toBeUndefined();

    expect(resend).toHaveBeenCalledWith("0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c");
  });

  it("lets a refused resend reach the caller instead of reporting success", async () => {
    const refusal = new Error("refused");
    const repository: ResendInvitationRepository = { resend: vi.fn().mockRejectedValue(refusal) };

    await expect(
      new ResendInvitation(repository).run("0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c"),
    ).rejects.toBe(refusal);
  });
});
