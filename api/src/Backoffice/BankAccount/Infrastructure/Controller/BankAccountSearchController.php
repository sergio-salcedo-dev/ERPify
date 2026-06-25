<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Controller;

use Erpify\Backoffice\BankAccount\Application\BankAccountSearcher;
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
 * Read-only accounts-by-bank surface. Public like the rest of `/backoffice` (the repo has no auth
 * yet); it exposes the full PII IBAN — see PRODUCTION_SECURITY_CHECKLIST.md and the auth follow-up.
 */
/**
 * The `_audit_canonical` route default tells the generic access-log hook that this read is already
 * recorded richly by an explicit `AuditLogger->log()` call ({@see BankAccountSearcher}, carrying the
 * resource id), so the generic path must not write a second, thinner audit row. The fact lives here, on
 * the route that owns it, not in a list inside the shared audit policy. The leading underscore keeps it
 * a framework-internal attribute, never bound as a controller argument.
 */
#[Route('/banks/{id}/accounts', name: self::ROUTE_NAME, defaults: ['_audit_canonical' => true], methods: ['GET'])]
final readonly class BankAccountSearchController
{
    public const string ROUTE_NAME = 'backoffice_bank_account_search';

    public function __construct(
        private BankAccountSearcher $bankAccountSearcher,
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
        string $id,
        #[MapQueryString]
        SearchQuery $query = new SearchQuery(),
    ): Response {
        return $this->searchResponder->respond(
            $this->bankAccountResourceMapper->toListPage(
                $this->bankAccountSearcher->search($id, new SearchBankAccountsQuery($query->toCriteria())),
            ),
            $query,
            self::ROUTE_NAME,
            ['id' => $id],
        );
    }
}
