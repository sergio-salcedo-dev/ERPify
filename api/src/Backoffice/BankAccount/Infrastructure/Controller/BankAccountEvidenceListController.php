<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Controller;

use Erpify\Backoffice\BankAccount\Application\Evidence\ListBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Infrastructure\Http\BankAccountEvidenceResourceMapper;
use Erpify\Backoffice\BankAccount\Infrastructure\Security\BankAccountPermission;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The evidence of one bank account with its documents' states and names (`bankAccount.readEvidence`, ADMIN only).
 * Like the Documents listing it refuses HEAD before anything is opened, answers 410 while the erasure ledger's
 * override is set (through the Documents seam), and is `no-store` because it holds names.
 */
#[Route(
    '/bank-accounts/{id}/evidence',
    name: 'backoffice_bank_account_evidence_list',
    defaults: ['_audit_resource_type' => 'BankAccount'],
    methods: ['GET'],
)]
#[IsGranted(BankAccountPermission::READ_EVIDENCE)]
final readonly class BankAccountEvidenceListController
{
    public function __construct(
        private ListBankAccountEvidence $listEvidence,
        private BankAccountEvidenceResourceMapper $mapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if ($request->isMethod(Request::METHOD_HEAD)) {
            throw new MethodNotAllowedHttpException([Request::METHOD_GET]);
        }

        $response = $this->resourceResponder->respondCollection(
            $this->mapper->toResources($this->listEvidence->list($id)),
        );
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
