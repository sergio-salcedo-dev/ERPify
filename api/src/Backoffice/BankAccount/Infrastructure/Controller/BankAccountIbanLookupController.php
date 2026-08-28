<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Controller;

use Erpify\Backoffice\BankAccount\Application\BankAccountIbanLookup;
use Erpify\Backoffice\BankAccount\Application\Query\LookupBankAccountByIbanQuery;
use Erpify\Backoffice\BankAccount\Infrastructure\Http\BankAccountResourceMapper;
use Erpify\Backoffice\BankAccount\Infrastructure\Security\BankAccountPermission;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Exact-match lookup of one account by IBAN, over a POST body rather than the GET `filters[]`
 * vocabulary — the IBAN never travels as a query-string value, so it is never written to an access log
 * or cached by an intermediary keyed on the URL (see the IBAN wire contract in `adding-endpoints.md`).
 * Read-only despite the verb: gated by `bankAccount.read`, like every other read controller in this
 * module — a resource is governed, not a route/method.
 */
#[Route('/bank-accounts/iban-lookup', name: self::ROUTE_NAME, methods: ['POST'])]
#[IsGranted(BankAccountPermission::READ)]
final readonly class BankAccountIbanLookupController
{
    public const string ROUTE_NAME = 'backoffice_bank_account_iban_lookup';

    public function __construct(
        private BankAccountIbanLookup $bankAccountIbanLookup,
        private BankAccountResourceMapper $bankAccountResourceMapper,
        private ResourceResponder $resourceResponder,
    ) {
    }

    public function __invoke(
        #[StrictRequestPayload]
        LookupBankAccountByIbanQuery $query,
    ): Response {
        $row = $this->bankAccountIbanLookup->lookup($query->canonicalIban());

        return $this->resourceResponder->respond(
            $this->bankAccountResourceMapper->toCollectionResource($row),
        );
    }
}
