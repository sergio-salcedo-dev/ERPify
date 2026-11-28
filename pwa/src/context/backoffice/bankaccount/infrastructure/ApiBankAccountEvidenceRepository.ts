import { inject, injectable } from "inversify";
import type { HttpClient, ResponseGuard } from "@/context/shared/http-client/domain/HttpClient";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import {
  EVIDENCE_DOCUMENT_STATES,
  EVIDENCE_KINDS,
  type BankAccountEvidence,
  type BankAccountEvidenceRepository,
  type EvidenceDocumentState,
  type EvidenceKind,
} from "../domain/BankAccountEvidence";

function isObjectRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

const KINDS: ReadonlySet<string> = new Set<EvidenceKind>(EVIDENCE_KINDS);
const STATES: ReadonlySet<string> = new Set<EvidenceDocumentState>(EVIDENCE_DOCUMENT_STATES);

function isBankAccountEvidence(value: unknown): value is BankAccountEvidence {
  return (
    isObjectRecord(value) &&
    typeof value.id === "string" &&
    typeof value.documentId === "string" &&
    typeof value.kind === "string" &&
    KINDS.has(value.kind) &&
    typeof value.attachedAt === "string" &&
    (value.documentState === null ||
      (typeof value.documentState === "string" && STATES.has(value.documentState))) &&
    (value.documentName === null || typeof value.documentName === "string") &&
    (value.documentSizeBytes === null || typeof value.documentSizeBytes === "number")
  );
}

interface EvidenceListResponse {
  data: BankAccountEvidence[];
}

interface EvidenceSingleResponse {
  data: BankAccountEvidence;
}

export const isEvidenceListResponse: ResponseGuard<EvidenceListResponse> = (
  body,
): body is EvidenceListResponse =>
  isObjectRecord(body) && Array.isArray(body.data) && body.data.every(isBankAccountEvidence);

export const isEvidenceSingleResponse: ResponseGuard<EvidenceSingleResponse> = (
  body,
): body is EvidenceSingleResponse => isObjectRecord(body) && isBankAccountEvidence(body.data);

function project(item: BankAccountEvidence): BankAccountEvidence {
  return {
    id: item.id,
    documentId: item.documentId,
    kind: item.kind,
    attachedAt: item.attachedAt,
    documentState: item.documentState,
    documentName: item.documentName,
    documentSizeBytes: item.documentSizeBytes,
  };
}

/** HTTP adapter over `GET|POST /bank-accounts/{id}/evidence`. No status is interpreted here. */
@injectable()
export class ApiBankAccountEvidenceRepository implements BankAccountEvidenceRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async list(accountId: string): Promise<BankAccountEvidence[]> {
    const response = await this.httpClient.get(
      API_ENDPOINTS.BACKOFFICE.BANK_ACCOUNTS.EVIDENCE_LIST(accountId),
      isEvidenceListResponse,
    );
    return response.data.map(project);
  }

  async attach(
    accountId: string,
    documentId: string,
    kind: EvidenceKind,
  ): Promise<BankAccountEvidence> {
    const response = await this.httpClient.post(
      API_ENDPOINTS.BACKOFFICE.BANK_ACCOUNTS.EVIDENCE_ATTACH(accountId),
      { documentId, kind },
      isEvidenceSingleResponse,
    );
    return project(response.data);
  }
}
