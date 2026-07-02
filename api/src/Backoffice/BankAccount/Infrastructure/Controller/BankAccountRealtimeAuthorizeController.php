<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Controller;

use Erpify\Backoffice\BankAccount\Domain\MercureBankAccountTopic;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Issues the Mercure subscriber authorization cookie scoped to the back-office bank-account topics
 * (the global collection topic + the per-bank accounts collection template + the per-account
 * template). The PWA calls this with credentials before opening the same-origin EventSource so the
 * hub delivers the private account updates. No account data is returned here.
 *
 * The two-segment `/bank-accounts/{id}` placeholder never captures this three-segment path, so the
 * route needs no priority over the detail route.
 */
final readonly class BankAccountRealtimeAuthorizeController
{
    public function __construct(
        private Authorization $authorization,
    ) {
    }

    #[Route(
        '/bank-accounts/realtime/authorize',
        name: 'backoffice_bank_account_realtime_authorize',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $cookie = $this->authorization->createCookie($request, [
            MercureBankAccountTopic::COLLECTION,
            MercureBankAccountTopic::COLLECTION_TEMPLATE,
            MercureBankAccountTopic::DETAIL_TEMPLATE,
        ]);

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->setCookie($cookie);

        return $response;
    }
}
