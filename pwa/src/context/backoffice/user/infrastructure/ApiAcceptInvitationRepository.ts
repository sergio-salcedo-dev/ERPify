import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";
import type { AcceptInvitationCommand } from "../domain/AcceptInvitationCommand";
import type { AcceptInvitationRepository } from "../domain/AcceptInvitationRepository";
import {
  AcceptInvitationOutcomeKind,
  AcceptInvitationProblemType,
  type AcceptInvitationOutcome,
} from "../domain/AcceptInvitationOutcome";

/** The wire body the accept endpoint expects. */
interface AcceptInvitationRequest {
  token: string;
  password: string;
  /** Stateless CSRF nonce — see {@link ApiAcceptInvitationRepository}. */
  _token: string;
}

/**
 * Maps the accept-invitation HTTP contract to an {@link AcceptInvitationOutcome}:
 *
 *  - 204 → accepted (httpOnly session cookie set server-side).
 *  - type `invalid-token` → invalid-link (dead token — routed by type, never the
 *    status alone).
 *  - type `validation-failed` → validation-error (the password failed policy).
 *  - anything else (403/401 origin/CSRF rejection, transport fault) → rethrow;
 *    it is not an accept outcome.
 *
 * The `_token` is a client-generated, single-use CSRF nonce (defense-in-depth):
 * the backend only length-checks it (>= 24), so a v7 UUID (36 chars) satisfies
 * the contract while keeping every client-side id on the one canonical generator
 * (never `crypto.randomUUID`; see `pwa/CLAUDE.md`).
 */
@injectable()
export class ApiAcceptInvitationRepository implements AcceptInvitationRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async accept(command: AcceptInvitationCommand): Promise<AcceptInvitationOutcome> {
    const request: AcceptInvitationRequest = {
      token: command.token,
      password: command.password,
      _token: uuidV7(),
    };
    try {
      await this.httpClient.post<AcceptInvitationRequest, void>(
        API_ENDPOINTS.BACKOFFICE.INVITATIONS.ACCEPT,
        request,
      );
      return { kind: AcceptInvitationOutcomeKind.ACCEPTED };
    } catch (error) {
      if (error instanceof HttpError) {
        return this.toOutcome(error);
      }
      throw error;
    }
  }

  private toOutcome(error: HttpError): AcceptInvitationOutcome {
    const { problem } = error;
    if (problem.type === AcceptInvitationProblemType.INVALID_TOKEN) {
      return { kind: AcceptInvitationOutcomeKind.INVALID_LINK };
    }
    if (problem.type === AcceptInvitationProblemType.VALIDATION_FAILED) {
      return {
        kind: AcceptInvitationOutcomeKind.VALIDATION_ERROR,
        violations: problem.violations ?? [],
      };
    }
    throw error;
  }
}
