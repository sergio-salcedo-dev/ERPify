<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankSearcher;
use Erpify\Shared\Application\UseCase\Result;
use Erpify\Shared\Infrastructure\Http\Responder\ResponderInterface;
use Erpify\Shared\Infrastructure\Persistence\PaginationMode;
use Erpify\Shared\Infrastructure\Persistence\QueryParam;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/banks', name: 'backoffice_bank_search', methods: ['GET'])]
final readonly class BankSearchController
{
    public function __construct(
        private BankSearcher $bankSearcher,
        private SerializerInterface $serializer,
        private ResponderInterface $responder,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $paginationModeParam = $request->query->get(QueryParam::PAGINATION_MODE->value);
        $paginationMode = null !== $paginationModeParam
            ? PaginationMode::tryFrom($paginationModeParam)
            : PaginationMode::LIGHT;

        $paginator = $this->bankSearcher->search([
            QueryParam::CURSOR->value => $request->query->get(QueryParam::CURSOR->value),
            QueryParam::PAGE->value => \max(1, $request->query->getInt(QueryParam::PAGE->value, 1)),
            QueryParam::PAGINATION_MODE->value => $paginationMode,
            QueryParam::ID->value => $request->query->get(QueryParam::ID->value),
        ]);

        /** @var array<int, mixed> $items */
        $items = \json_decode(
            $this->serializer->serialize(
                \array_values(\iterator_to_array($paginator)),
                'json',
                ['groups' => ['bank:search']],
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $this->responder->respond(Result::ok($items));
    }
}
