<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Controller;

use Erpify\Backoffice\BankAccount\Application\BankAccountCollectionSearcher;
use Erpify\Backoffice\BankAccount\Application\Query\SearchBankAccountsQuery;
use Erpify\Backoffice\BankAccount\Infrastructure\Http\BankAccountResourceMapper;
use Erpify\Shared\Search\Application\Http\SearchQuery;
use Erpify\Shared\Search\Infrastructure\Http\SearchResponder;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * Read-only cross-bank account collection surface. Public like the rest of `/backoffice` (the repo has
 * no auth yet); it exposes the full PII IBAN — see PRODUCTION_SECURITY_CHECKLIST.md and the auth
 * follow-up.
 *
 * The route name is distinct from the nested {@see BankAccountSearchController} (which owns
 * `backoffice_bank_account_search`): this flat collection is its own keyset chain, and the responder
 * mints cursor links against this route name with no `{id}` param to thread.
 *
 * The `_audit_canonical` route default tells the generic access-log hook that this read is already
 * recorded by an explicit `AuditLogger->log()` call ({@see BankAccountCollectionSearcher}), so the
 * generic path must not write a second, thinner audit row. The fact lives here, on the route that owns
 * it, not in a list inside the shared audit policy. The leading underscore keeps it a framework-internal
 * attribute, never bound as a controller argument.
 */
#[Route('/bank-accounts', name: self::ROUTE_NAME, defaults: ['_audit_canonical' => true], methods: ['GET'])]
final readonly class BankAccountSearchCollectionController
{
    public const string ROUTE_NAME = 'backoffice_bank_account_collection_search';

    public function __construct(
        private BankAccountCollectionSearcher $bankAccountCollectionSearcher,
        private BankAccountResourceMapper $bankAccountResourceMapper,
        private SearchResponder $searchResponder,
    ) {
    }

    /**
     * @throws ExceptionInterface
     * @throws JsonException
     * @throws MessengerExceptionInterface
     */
    public function __invoke(
        #[MapQueryString]
        SearchQuery $query = new SearchQuery(),
    ): Response {
        return $this->searchResponder->respond(
            $this->bankAccountResourceMapper->toCollectionPage(
                $this->bankAccountCollectionSearcher->search(new SearchBankAccountsQuery($query->toCriteria())),
            ),
            $query,
            self::ROUTE_NAME,
        );
    }
}
