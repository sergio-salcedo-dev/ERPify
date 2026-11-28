<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Controller;

use Erpify\Backoffice\BankAccount\Application\Evidence\ListBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Infrastructure\Http\BankAccountEvidenceResourceMapper;
use Erpify\Backoffice\BankAccount\Infrastructure\Security\BankAccountPermission;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Uuid\Domain\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * `GET /api/v1/backoffice/bank-accounts/{id}/evidence`: the documents the account holds as evidence, most recently
 * attached first, each with its opened name and lifecycle state. ADMIN-only — `bankAccount.read` and the Documents
 * custody verb together. The bytes are not here: a `ready` document is downloaded through the audited Documents read
 * route, by the `documentId` this lists.
 *
 * Answers 410 `gone` while the erasure ledger's override is set, as the Documents listing does, because a restored
 * key would open a name the ledger recorded as erased. HEAD is refused: its body is dropped after the fact, and the
 * names would have been opened for nothing. The response carries names, so it is `no-store`.
 */
#[Route(
    '/bank-accounts/{id}/evidence',
    name: 'backoffice_bank_account_evidence_list',
    defaults: ['_audit_resource_type' => 'BankAccount'],
    methods: ['GET'],
)]
#[IsGranted(BankAccountPermission::READ)]
#[IsGranted(BankAccountPermission::EVIDENCE_CUSTODY)]
final readonly class BankAccountEvidenceListController
{
    public function __construct(
        private ListBankAccountEvidence $listEvidence,
        private BankAccountEvidenceResourceMapper $mapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    /**
     * @throws ExceptionInterface
     */
    public function __invoke(Request $request, string $id): Response
    {
        if ($request->isMethod(Request::METHOD_HEAD)) {
            throw new MethodNotAllowedHttpException([Request::METHOD_GET]);
        }

        // A malformed id is a request-target error (400 `invalid-uuid`), answered before any lookup.
        Uuid::ensure($id);

        $response = $this->resourceResponder->respondCollection(
            $this->mapper->toResources($this->listEvidence->list($id)),
        );
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
