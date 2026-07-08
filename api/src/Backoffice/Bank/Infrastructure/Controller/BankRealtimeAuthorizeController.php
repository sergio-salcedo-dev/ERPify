<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Domain\MercureBankTopic;
use Erpify\Backoffice\Bank\Infrastructure\Security\BankPermission;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Issues the Mercure subscriber authorization cookie scoped to the back-office
 * bank topics (collection + per-bank template). The PWA calls this with
 * credentials before opening the same-origin EventSource so the hub delivers
 * the private bank updates. No bank data is returned here.
 */
final readonly class BankRealtimeAuthorizeController
{
    public function __construct(
        private Authorization $authorization,
    ) {
    }

    #[Route('/banks/realtime/authorize', name: 'backoffice_bank_realtime_authorize', methods: ['GET'])]
    #[IsGranted(BankPermission::READ)]
    public function __invoke(Request $request): Response
    {
        $cookie = $this->authorization->createCookie($request, [
            MercureBankTopic::COLLECTION,
            MercureBankTopic::DETAIL_TEMPLATE,
        ]);

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->setCookie($cookie);

        return $response;
    }
}
