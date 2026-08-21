import { beforeEach, describe, expect, it, vi } from "vitest";
import {
  beginSessionExpiry,
  endSessionExpiry,
  isSessionExpiring,
  subscribeToSessionExpiry,
} from "@/context/shared/access/application/sessionExpiry";

describe("the session-expiry claim", () => {
  beforeEach(() => {
    endSessionExpiry();
  });

  it("admits one claimant, which is what makes a burst of 401s navigate once", () => {
    expect(beginSessionExpiry()).toBe(true);
    expect(beginSessionExpiry()).toBe(false);
    expect(isSessionExpiring()).toBe(true);
  });

  it("can be claimed again once released", () => {
    beginSessionExpiry();
    endSessionExpiry();

    // The release is not tidiness: a document that stayed must be able to bounce on a later
    // 401, and a claim nobody can retake is how every later 401 got swallowed in silence.
    expect(beginSessionExpiry()).toBe(true);
  });

  it("tells its subscribers both times", () => {
    const listener = vi.fn();
    subscribeToSessionExpiry(listener);

    beginSessionExpiry();
    endSessionExpiry();

    expect(listener.mock.calls).toEqual([[true], [false]]);
  });

  it("says nothing when the state does not actually change", () => {
    const listener = vi.fn();
    beginSessionExpiry();
    subscribeToSessionExpiry(listener);

    beginSessionExpiry();

    expect(listener).not.toHaveBeenCalled();
  });

  it("stops telling a listener that unsubscribed", () => {
    const listener = vi.fn();
    const unsubscribe = subscribeToSessionExpiry(listener);

    unsubscribe();
    beginSessionExpiry();

    expect(listener).not.toHaveBeenCalled();
  });
});
