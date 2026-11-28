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

/** The wire item as it arrives: structurally checked, its two vocabularies not yet. */
interface EvidenceWireItem {
  id: string;
  documentId: string;
  kind: string;
  attachedAt: string;
  documentState: string | null;
  documentName: string | null;
  documentSizeBytes: number | null;
}

/**
 * Structure only. A `kind` or `documentState` this client does not know is not a malformed item —
 * the server's vocabulary can grow ahead of the client — so it is mapped to `null` rather than
 * failing the whole list; a missing field or a wrong type still does.
 */
function isEvidenceWireItem(value: unknown): value is EvidenceWireItem {
  return (
    isObjectRecord(value) &&
    typeof value.id === "string" &&
    typeof value.documentId === "string" &&
    typeof value.kind === "string" &&
    typeof value.attachedAt === "string" &&
    (value.documentState === null || typeof value.documentState === "string") &&
    (value.documentName === null || typeof value.documentName === "string") &&
    (value.documentSizeBytes === null || typeof value.documentSizeBytes === "number")
  );
}

interface EvidenceListResponse {
  data: EvidenceWireItem[];
}

interface EvidenceSingleResponse {
  data: EvidenceWireItem;
}

export const isEvidenceListResponse: ResponseGuard<EvidenceListResponse> = (
  body,
): body is EvidenceListResponse =>
  isObjectRecord(body) && Array.isArray(body.data) && body.data.every(isEvidenceWireItem);

export const isEvidenceSingleResponse: ResponseGuard<EvidenceSingleResponse> = (
  body,
): body is EvidenceSingleResponse => isObjectRecord(body) && isEvidenceWireItem(body.data);

function project(item: EvidenceWireItem): BankAccountEvidence {
  return {
    id: item.id,
    documentId: item.documentId,
    kind: KINDS.has(item.kind) ? (item.kind as EvidenceKind) : null,
    attachedAt: item.attachedAt,
    documentState:
      item.documentState !== null && STATES.has(item.documentState)
        ? (item.documentState as EvidenceDocumentState)
        : null,
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
