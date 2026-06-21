import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { ProblemDisplay } from "@/components/erpify/ProblemDisplay";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { NodeEnv } from "@/context/shared/environment/domain/NodeEnv";

const baseProblem: ProblemDetails = {
  type: "bank-not-found",
  title: "Bank not found",
  status: 404,
  detail: "No bank exists with that identifier.",
  instance: "01926e7e-7b8a-7c4e-9f31-a2b7d1e4f5c6",
  "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000001",
};

const SENSITIVE_DEBUG = {
  exception_class: String.raw`Symfony\Component\HttpKernel\Exception\NotFoundHttpException`,
  message: "secret token=sk_live_42",
  file: "/app/src/Backoffice/Bank/Infrastructure/Controller/BankSearchController.php",
  line: 41,
  previous_chain: [
    {
      exception_class: "InvalidArgumentException",
      message: "internal: connection_string=postgres://user:hunter2@db",
      file: "/app/vendor/foo/bar.php",
      line: 12,
    },
  ],
};

describe("ProblemDisplay", () => {
  it("renders title and detail verbatim from the API envelope", () => {
    render(<ProblemDisplay problem={baseProblem} />);
    expect(screen.getByTestId("problem-display__title")).toHaveTextContent("Bank not found");
    expect(screen.getByTestId("problem-display__detail")).toHaveTextContent(
      "No bank exists with that identifier.",
    );
  });

  it("renders a status pill and the opaque problem type label", () => {
    render(<ProblemDisplay problem={baseProblem} />);
    expect(screen.getByTestId("problem-display__status")).toHaveTextContent("HTTP 404");
    expect(screen.getByTestId("problem-display__type")).toHaveTextContent("bank-not-found");
  });

  it("falls back to a textual status when the synthetic 0 sentinel is used", () => {
    render(
      <ProblemDisplay problem={{ ...baseProblem, status: 0, title: "Network unreachable" }} />,
    );
    expect(screen.getByTestId("problem-display__status")).toHaveTextContent("No response");
  });

  it("renders the correlation ID as a copyable chip", () => {
    render(<ProblemDisplay problem={baseProblem} />);
    expect(screen.getByRole("button", { name: /Copy correlation ID/ })).toBeInTheDocument();
  });

  it("renders violations attached to fields when present", () => {
    const violations = [
      { field: "name", message: "must not be blank", code: "NotBlank" },
      { field: "iban", message: "invalid format", code: "Iban" },
    ];
    render(<ProblemDisplay problem={{ ...baseProblem, status: 422, violations }} />);
    const list = screen.getByTestId("problem-display__violations");
    expect(list).toHaveTextContent("name");
    expect(list).toHaveTextContent("must not be blank");
    expect(list).toHaveTextContent("iban");
    expect(list).toHaveTextContent("invalid format");
  });

  it("uses aria-live='assertive' for 5xx errors", () => {
    const { container } = render(
      <ProblemDisplay problem={{ ...baseProblem, status: 500, title: "Internal server error" }} />,
    );
    expect(container.querySelector('[role="alert"]')).toHaveAttribute("aria-live", "assertive");
  });

  it("uses aria-live='polite' for 4xx errors", () => {
    const { container } = render(<ProblemDisplay problem={baseProblem} />);
    expect(container.querySelector('[role="alert"]')).toHaveAttribute("aria-live", "polite");
  });

  it("exposes the problem type and status via data attributes for routing/styling hooks", () => {
    const { container } = render(<ProblemDisplay problem={baseProblem} />);
    const root = container.querySelector('[data-problem-type="bank-not-found"]');
    expect(root).toBeTruthy();
    expect(root).toHaveAttribute("data-problem-status", "404");
  });
});

describe("ProblemDisplay — debug rendering (development / test)", () => {
  beforeEach(() => {
    vi.stubEnv("NODE_ENV", NodeEnv.DEVELOPMENT);
  });

  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it("renders the API debug block with exception, file:line and the previous chain", () => {
    render(
      <ProblemDisplay
        problem={{ ...baseProblem, status: 500, title: "Server error", debug: SENSITIVE_DEBUG }}
      />,
    );
    const debug = screen.getByTestId("problem-display__debug");
    expect(debug).toBeInTheDocument();
    expect(debug).toHaveTextContent(String.raw`Symfony\Component\HttpKernel`);
    expect(debug).toHaveTextContent("BankSearchController.php");
    expect(debug).toHaveTextContent(":41");
    expect(debug).toHaveTextContent("InvalidArgumentException");
  });

  it("hides the debug block when the API envelope has no `debug` extension", () => {
    render(<ProblemDisplay problem={baseProblem} />);
    expect(screen.queryByTestId("problem-display__debug")).toBeNull();
  });
});

describe("ProblemDisplay — production redaction (only generic messages)", () => {
  beforeEach(() => {
    vi.stubEnv("NODE_ENV", NodeEnv.PRODUCTION);
  });

  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it("never renders the debug block, even when the API leaks one", () => {
    render(
      <ProblemDisplay
        problem={{ ...baseProblem, status: 500, title: "Server error", debug: SENSITIVE_DEBUG }}
      />,
    );
    expect(screen.queryByTestId("problem-display__debug")).toBeNull();
  });

  it("never leaks framework class names, file paths, line numbers or secrets", () => {
    const { container } = render(
      <ProblemDisplay
        problem={{
          ...baseProblem,
          status: 500,
          title: "Server error",
          detail: "Generic message safe for users.",
          debug: SENSITIVE_DEBUG,
        }}
      />,
    );
    const text = container.textContent ?? "";
    expect(text).not.toMatch(/Symfony\\Component/);
    expect(text).not.toMatch(/HttpKernel/);
    expect(text).not.toMatch(/\.php/);
    expect(text).not.toMatch(/BankSearchController/);
    expect(text).not.toMatch(/sk_live_/);
    expect(text).not.toMatch(/hunter2/);
    expect(text).not.toMatch(/connection_string/);
  });

  it("still renders the API-provided generic title and detail (safe for end users)", () => {
    render(
      <ProblemDisplay
        problem={{
          ...baseProblem,
          status: 500,
          title: "Something went wrong",
          detail: "Please try again later.",
          debug: SENSITIVE_DEBUG,
        }}
      />,
    );
    expect(screen.getByTestId("problem-display__title")).toHaveTextContent("Something went wrong");
    expect(screen.getByTestId("problem-display__detail")).toHaveTextContent(
      "Please try again later.",
    );
  });
});
