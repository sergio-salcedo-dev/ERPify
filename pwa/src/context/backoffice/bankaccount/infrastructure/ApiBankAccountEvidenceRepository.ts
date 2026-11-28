import { inject, injectable } from "inversify";
import type { HttpClient, ResponseGuard } from "@/context/shared/http-client/domain/HttpClient";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import {
  toBankAccountEvidence,
  type BankAccountEvidence,
  type BankAccountEvidenceKind,
} from "../domain/BankAccountEvidence";
import type { BankAccountEvidenceRepository } from "../domain/BankAccountEvidenceRepository";

interface Envelope {
  data: unknown;
}

const isListEnvelope: ResponseGuard<Envelope> = (body): body is Envelope => {
  if (typeof body !== "object" || body === null) return false;
  const { data } = body as { data?: unknown };
  return Array.isArray(data) && data.every((item) => toBankAccountEvidence(item) !== null);
};

const isItemEnvelope: ResponseGuard<Envelope> = (body): body is Envelope => {
  if (typeof body !== "object" || body === null) return false;
  return toBankAccountEvidence((body as { data?: unknown }).data) !== null;
};

/** Each item already passed the envelope guard, so the reading cannot fail here. */
function read(value: unknown): BankAccountEvidence {
  const evidence = toBankAccountEvidence(value);
  if (evidence === null)
    throw new TypeError("An evidence item passed its guard and then failed it.");
  return evidence;
}

/**
 * HTTP adapter over `GET`/`POST /bank-accounts/{id}/evidence` and `DELETE …/evidence/{evidenceId}`.
 * No status is interpreted here.
 */
@injectable()
export class ApiBankAccountEvidenceRepository implements BankAccountEvidenceRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async list(bankAccountId: string): Promise<BankAccountEvidence[]> {
    const response = await this.httpClient.get(
      API_ENDPOINTS.BACKOFFICE.BANK_ACCOUNTS.EVIDENCE_LIST(bankAccountId),
      isListEnvelope,
    );
    return (response.data as unknown[]).map(read);
  }

  async attach(
    bankAccountId: string,
    documentId: string,
    kind: BankAccountEvidenceKind,
  ): Promise<BankAccountEvidence> {
    const response = await this.httpClient.post(
      API_ENDPOINTS.BACKOFFICE.BANK_ACCOUNTS.EVIDENCE_ATTACH(bankAccountId),
      { documentId, kind },
      isItemEnvelope,
    );
    return read(response.data);
  }

  async detach(bankAccountId: string, evidenceId: string): Promise<void> {
    await this.httpClient.delete(
      API_ENDPOINTS.BACKOFFICE.BANK_ACCOUNTS.EVIDENCE_DETACH(bankAccountId, evidenceId),
    );
  }
}
