import { inject, injectable } from "inversify";
import type { HttpClient, ResponseGuard } from "@/context/shared/http-client/domain/HttpClient";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import {
  isBankAccountEvidence,
  type BankAccountEvidence,
  type BankAccountEvidenceKind,
} from "../domain/BankAccountEvidence";
import type { BankAccountEvidenceRepository } from "../domain/BankAccountEvidenceRepository";

interface EvidenceListResponse {
  data: BankAccountEvidence[];
}

interface EvidenceItemResponse {
  data: BankAccountEvidence;
}

const isEvidenceList: ResponseGuard<EvidenceListResponse> = (
  body,
): body is EvidenceListResponse => {
  if (typeof body !== "object" || body === null) return false;
  const { data } = body as { data?: unknown };
  return Array.isArray(data) && data.every(isBankAccountEvidence);
};

const isEvidenceItem: ResponseGuard<EvidenceItemResponse> = (
  body,
): body is EvidenceItemResponse => {
  if (typeof body !== "object" || body === null) return false;
  return isBankAccountEvidence((body as { data?: unknown }).data);
};

/** HTTP adapter over `GET`/`POST /bank-accounts/{id}/evidence`. No status is interpreted here. */
@injectable()
export class ApiBankAccountEvidenceRepository implements BankAccountEvidenceRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async list(bankAccountId: string): Promise<BankAccountEvidence[]> {
    const response = await this.httpClient.get(
      API_ENDPOINTS.BACKOFFICE.BANK_ACCOUNTS.EVIDENCE_LIST(bankAccountId),
      isEvidenceList,
    );
    return response.data;
  }

  async attach(
    bankAccountId: string,
    documentId: string,
    kind: BankAccountEvidenceKind,
  ): Promise<BankAccountEvidence> {
    const response = await this.httpClient.post(
      API_ENDPOINTS.BACKOFFICE.BANK_ACCOUNTS.EVIDENCE_ATTACH(bankAccountId),
      { documentId, kind },
      isEvidenceItem,
    );
    return response.data;
  }
}
