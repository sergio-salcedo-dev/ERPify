<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Controller;

use Erpify\Backoffice\BankAccount\Application\Command\AttachBankAccountEvidenceCommand;
use Erpify\Backoffice\BankAccount\Application\Evidence\AttachBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Infrastructure\Http\BankAccountEvidenceResourceMapper;
use Erpify\Backoffice\BankAccount\Infrastructure\Security\BankAccountPermission;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use Erpify\Shared\Uuid\Domain\Uuid;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * `POST /api/v1/backoffice/bank-accounts/{id}/evidence`: attaches a document an earlier upload filed to the account,
 * as evidence of the given kind. The document travels by id: the original was sent through the Documents two-step
 * upload and is never re-sent here. ADMIN-only — `bankAccount.write` and the Documents custody verb together.
 *
 * Answers 201 with the link and the document's opened name and state. The refusals are the use case's and the error
 * contract's: 400 for a malformed account id, 404 for an unknown account, 422 for a body the mapping or the retention
 * check refuses, 409 for an erased document or one the account already holds.
 */
#[Route(
    '/bank-accounts/{id}/evidence',
    name: 'backoffice_bank_account_evidence_attach',
    defaults: ['_audit_resource_type' => 'BankAccount'],
    methods: ['POST'],
)]
#[IsGranted(BankAccountPermission::WRITE)]
#[IsGranted(BankAccountPermission::EVIDENCE_CUSTODY)]
final readonly class BankAccountEvidenceAttachController
{
    public function __construct(
        private AttachBankAccountEvidence $attachEvidence,
        private BankAccountEvidenceResourceMapper $mapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    /**
     * @throws ExceptionInterface
     */
    public function __invoke(
        string $id,
        #[StrictRequestPayload]
        AttachBankAccountEvidenceCommand $command,
    ): Response {
        // A malformed id is a request-target error (400 `invalid-uuid`), answered before any lookup.
        Uuid::ensure($id);

        $response = $this->resourceResponder->respond(
            $this->mapper->toResource($this->attachEvidence->attach($id, $command)),
            Response::HTTP_CREATED,
        );
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
