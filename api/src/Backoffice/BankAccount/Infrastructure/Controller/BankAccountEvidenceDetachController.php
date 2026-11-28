<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Controller;

use Erpify\Backoffice\BankAccount\Application\Evidence\DetachBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Infrastructure\Security\BankAccountPermission;
use Erpify\Shared\Http\Infrastructure\Responder\ResponderInterface;
use Erpify\Shared\Kernel\Application\Result;
use Erpify\Shared\Uuid\Domain\Uuid;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `DELETE /api/v1/backoffice/bank-accounts/{id}/evidence/{evidenceId}`: removes one link, so the document can be
 * attached to the account it belongs to. The document stays filed. ADMIN-only, with the attach's two verbs. Answers
 * 204; 400 for a malformed id, 404 for an unknown account or a link the account does not hold.
 */
#[Route(
    '/bank-accounts/{id}/evidence/{evidenceId}',
    name: 'backoffice_bank_account_evidence_detach',
    defaults: ['_audit_resource_type' => 'BankAccount'],
    methods: ['DELETE'],
)]
#[IsGranted(BankAccountPermission::WRITE)]
#[IsGranted(BankAccountPermission::EVIDENCE_CUSTODY)]
final readonly class BankAccountEvidenceDetachController
{
    public function __construct(
        private DetachBankAccountEvidence $detachEvidence,
        private ResponderInterface $responder,
    ) {
    }

    public function __invoke(string $id, string $evidenceId): Response
    {
        // Malformed ids are request-target errors (400 `invalid-uuid`), answered before any lookup.
        Uuid::ensure($id);
        Uuid::ensure($evidenceId);

        $this->detachEvidence->detach($id, $evidenceId);

        return $this->responder->respond(Result::noContent());
    }
}
