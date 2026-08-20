import { describe, expect, it } from "vitest";
import {
  SystemStatus,
  aggregateSystemStatus,
  componentRollCall,
  type NamedComponentStatus,
} from "@/context/shared/system-status/domain/SystemStatus";

describe("componentRollCall", () => {
  it("names every component with its status", () => {
    expect(
      componentRollCall([
        { name: "BackOffice API", status: SystemStatus.OPERATIONAL },
        { name: "Database", status: SystemStatus.DEGRADED },
      ]),
    ).toBe("BackOffice API: Operational. Database: Degraded");
  });

  it("changes when a component moves under an aggregate that does not", () => {
    // The defect this exists for: worst-wins folds a non-dominant component away, so the banner
    // headline is identical before and after. Without this line the change is visible in a row
    // and announced nowhere.
    const before: readonly NamedComponentStatus[] = [
      { name: "BackOffice API", status: SystemStatus.DISRUPTED },
      { name: "Database", status: SystemStatus.OPERATIONAL },
    ];
    const after: readonly NamedComponentStatus[] = [
      { name: "BackOffice API", status: SystemStatus.DISRUPTED },
      { name: "Database", status: SystemStatus.DEGRADED },
    ];

    const aggregate = (components: readonly NamedComponentStatus[]) =>
      aggregateSystemStatus(components.map(({ status }) => ({ status, datetime: null }))).status;

    expect(aggregate(after)).toBe(aggregate(before));
    expect(componentRollCall(after)).not.toBe(componentRollCall(before));
  });

  it("is empty for no components, so a caller adding punctuation says nothing", () => {
    expect(componentRollCall([])).toBe("");
  });
});
