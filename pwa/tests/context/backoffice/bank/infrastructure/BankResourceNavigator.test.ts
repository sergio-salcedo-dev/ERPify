import { describe, expect, it, vi } from "vitest";
import { BankResourceNavigator } from "@/context/backoffice/bank/infrastructure/BankResourceNavigator";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import type { BankSearchPage } from "@/context/backoffice/bank/domain/BankRepository";
import type { BankSearchNavigator } from "@/context/backoffice/bank/application/BankSearchNavigator";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";

const BETA = Bank.fromPrimitives({
  id: "22222222-2222-4222-8222-222222222222",
  name: "Beta Bank",
  shortName: "BETA",
  createdAt: "2026-01-02T10:00:00Z",
  updatedAt: "2026-04-16T14:30:00Z",
  accountCount: 0,
});

const PAGE: BankSearchPage = {
  banks: [BETA],
  hasNext: false,
  hasPrev: true,
  count: null,
  links: { next: null, prev: "/api/back_office/banks?before=cursor&limit=25" },
};

const LINK = "/api/back_office/banks?after=cursor&limit=25";

const PROBLEM: ProblemDetails = {
  type: "about:blank",
  title: "Boom",
  status: 500,
  instance: "01926e7e-7b8a-7c4e-9f31-000000000500",
  "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000500",
};

describe("BankResourceNavigator", () => {
  it("forwards the link verbatim and remaps the followed page to {items}", async () => {
    const follow = vi.fn().mockResolvedValue(PAGE);
    const navigator = new BankResourceNavigator({ follow } satisfies BankSearchNavigator);

    const result = await navigator.follow(LINK);

    expect(follow).toHaveBeenCalledWith(LINK);
    expect(result).toEqual({
      items: [BETA],
      hasNext: false,
      hasPrev: true,
      count: null,
      links: { next: null, prev: "/api/back_office/banks?before=cursor&limit=25" },
    });
  });

  it("propagates a follow failure untouched", async () => {
    const error = new HttpError(PROBLEM);
    const navigator = new BankResourceNavigator({
      follow: vi.fn().mockRejectedValue(error),
    } satisfies BankSearchNavigator);

    await expect(navigator.follow(LINK)).rejects.toBe(error);
  });
});
