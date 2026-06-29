import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { ActorChip } from "@/context/backoffice/audit/infrastructure/ui/ActorChip";

const ACTOR_ID = "019f0427-61f7-71d6-ae8f-b91d41227c29";

describe("ActorChip", () => {
  it("shows the type, a middle-truncated id, and a copy control when an id is present", () => {
    render(<ActorChip actorType="user" actorId={ACTOR_ID} testId="row-actor" />);
    expect(screen.getByText("user")).toBeInTheDocument();
    // Middle-truncated, but the full id is the copy value + title.
    expect(screen.getByText("019f04…7c29")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /copiar id de actor/i })).toBeInTheDocument();
  });

  it("omits id and copy for actors that never carry one (anonymous, system)", () => {
    render(<ActorChip actorType="anonymous" actorId={null} />);
    expect(screen.getByText("anonymous")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /copiar id de actor/i })).toBeNull();
  });

  it("renders the anonymized GDPR variant as a distinct thing, never an id", () => {
    render(<ActorChip actorType="user" actorId={ACTOR_ID} actorErased testId="row-actor" />);
    expect(screen.getByText("anonimizado (GDPR)")).toBeInTheDocument();
    expect(screen.getByText("· no identificable")).toBeInTheDocument();
    // The post-erasure UUID is NEVER shown as an id, and there is nothing to copy.
    expect(screen.queryByText(/019f04/)).toBeNull();
    expect(screen.queryByRole("button", { name: /copiar id de actor/i })).toBeNull();
  });
});
