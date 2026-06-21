import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

export class HttpError extends Error {
  readonly problem: ProblemDetails;

  constructor(problem: ProblemDetails) {
    super(problem.title);
    this.name = "HttpError";
    this.problem = problem;
  }
}
