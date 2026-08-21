import { beforeEach, describe, expect, it, vi } from "vitest";
import {
  claimDeparture,
  currentDeparture,
  releaseDeparture,
  subscribeToDeparture,
  DepartureReason,
} from "@/context/shared/navigation/application/departure";

describe("the departure claim", () => {
  beforeEach(() => {
    releaseDeparture();
  });

  it("admits one claimant, which is what makes a burst of 401s navigate once", () => {
    expect(claimDeparture(DepartureReason.SESSION_EXPIRED)).toBe(true);
    expect(claimDeparture(DepartureReason.SESSION_EXPIRED)).toBe(false);
    expect(currentDeparture()).toBe(DepartureReason.SESSION_EXPIRED);
  });

  // The single flight is doing a second job here, and this is it: a 401 arriving while the
  // sign-out is revoking cannot start a second, contradictory navigation to "session expired".
  it("refuses a second claimant with a different reason, and keeps the first one's", () => {
    expect(claimDeparture(DepartureReason.SIGN_OUT)).toBe(true);

    expect(claimDeparture(DepartureReason.SESSION_EXPIRED)).toBe(false);
    expect(currentDeparture()).toBe(DepartureReason.SIGN_OUT);
  });

  it("can be claimed again once released", () => {
    claimDeparture(DepartureReason.SESSION_EXPIRED);
    releaseDeparture();

    // The release is not tidiness: a document that stayed must be able to bounce on a later
    // 401, and a claim nobody can retake is how every later 401 got swallowed in silence.
    expect(claimDeparture(DepartureReason.SESSION_EXPIRED)).toBe(true);
  });

  it("tells its subscribers both times, and with the reason", () => {
    const listener = vi.fn();
    subscribeToDeparture(listener);

    claimDeparture(DepartureReason.SIGN_OUT);
    releaseDeparture();

    expect(listener.mock.calls).toEqual([[DepartureReason.SIGN_OUT], [null]]);
  });

  it("says nothing when the state does not actually change", () => {
    const listener = vi.fn();
    claimDeparture(DepartureReason.SESSION_EXPIRED);
    subscribeToDeparture(listener);

    claimDeparture(DepartureReason.SESSION_EXPIRED);

    expect(listener).not.toHaveBeenCalled();
  });

  it("stops telling a listener that unsubscribed", () => {
    const listener = vi.fn();
    const unsubscribe = subscribeToDeparture(listener);

    unsubscribe();
    claimDeparture(DepartureReason.SESSION_EXPIRED);

    expect(listener).not.toHaveBeenCalled();
  });
});
