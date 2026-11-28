<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Controller;

use Erpify\Backoffice\BankAccount\Application\Command\AttachBankAccountEvidenceCommand;
use Erpify\Backoffice\BankAccount\Application\Evidence\AttachBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Infrastructure\Http\BankAccountEvidenceResourceMapper;
use Erpify\Backoffice\BankAccount\Infrastructure\Security\BankAccountPermission;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Attaches an already-filed document to a bank account as evidence (`bankAccount.attachEvidence`, ADMIN only). The
 * bytes travel through the Documents upload first; this route carries ids only, so it is an ordinary JSON route with
 * the default body bound. The response holds the document's name, so it is `no-store`.
 */
#[Route(
    '/bank-accounts/{id}/evidence',
    name: 'backoffice_bank_account_evidence_attach',
    defaults: ['_audit_resource_type' => 'BankAccount'],
    methods: ['POST'],
)]
#[IsGranted(BankAccountPermission::ATTACH_EVIDENCE)]
final readonly class BankAccountEvidencePostController
{
    public function __construct(
        private AttachBankAccountEvidence $attachEvidence,
        private BankAccountEvidenceResourceMapper $mapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    public function __invoke(
        string $id,
        #[StrictRequestPayload]
        AttachBankAccountEvidenceCommand $command,
    ): Response {
        $response = $this->resourceResponder->respond(
            $this->mapper->toResource($this->attachEvidence->attach($id, $command)),
            Response::HTTP_CREATED,
        );
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
