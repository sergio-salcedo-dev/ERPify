"use client";

import { useState } from "react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import type { CrudRepository } from "../domain/CrudRepository";

/** create/update/delete lifecycle over a container-resolved repository. */
export function useResourceMutations<T, TInput>(repositoryKey: string) {
  const [submitting, setSubmitting] = useState(false);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const repo = (): CrudRepository<T, TInput> =>
    container.get<CrudRepository<T, TInput>>(repositoryKey);

  const create = async (input: TInput): Promise<T> => repo().create(input);
  const update = async (id: string, input: TInput): Promise<T> => repo().update(id, input);
  const remove = async (id: string): Promise<void> => repo().delete(id);

  return { create, update, remove, submitting, setSubmitting, problem, setProblem, HttpError };
}
