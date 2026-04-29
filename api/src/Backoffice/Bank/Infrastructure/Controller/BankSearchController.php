<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Controller;

use Erpify\Backoffice\Bank\Application\BankSearcher;
use Erpify\Shared\Application\UseCase\Result;
use Erpify\Shared\Domain\Search\PaginationMode;
use Erpify\Shared\Infrastructure\Http\Responder\ResponderInterface;
use Erpify\Shared\Infrastructure\Persistence\PaginatorCursorFactory;
use Erpify\Shared\Infrastructure\Persistence\QueryParam;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[Route('/banks', name: 'backoffice_bank_search', methods: ['GET'])]
final readonly class BankSearchController
{
    public function __construct(
        private BankSearcher $bankSearcher,
        private NormalizerInterface $normalizer,
        private ResponderInterface $responder,
        private PaginatorCursorFactory $paginatorCursorFactory,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $paginationModeParam = $request->query->get(QueryParam::PAGINATION_MODE->value);
        } catch (BadRequestException $badRequestException) {
            throw new BadRequestHttpException(
                \sprintf('Invalid "%s" query parameter.', QueryParam::PAGINATION_MODE->value),
                $badRequestException,
            );
        }

        $paginationMode = null === $paginationModeParam
            ? PaginationMode::LIGHT
            : (PaginationMode::tryFrom($paginationModeParam)
                ?? throw new BadRequestHttpException(\sprintf(
                    'Unknown pagination mode: "%s".',
                    $paginationModeParam,
                )));

        try {
            $paginator = $this->bankSearcher->search([
                QueryParam::CURSOR->value => $request->query->get(QueryParam::CURSOR->value),
                QueryParam::PAGE->value => \max(1, $request->query->getInt(QueryParam::PAGE->value, 1)),
                QueryParam::LIMIT->value => $request->query->get(QueryParam::LIMIT->value),
                QueryParam::PAGINATION_MODE->value => $paginationMode,
                QueryParam::IDS->value => $request->query->get(QueryParam::IDS->value),
            ]);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new BadRequestHttpException($invalidArgumentException->getMessage(), $invalidArgumentException);
        }

        /** @var array<int, mixed> $items */
        $items = $this->normalizer->normalize(
            \array_values(\iterator_to_array($paginator)),
            'json',
            ['groups' => ['aggregate:default', 'bank:search']],
        );

        return $this->responder->respond(Result::ok([
            'items' => $items,
            'pagination' => [
                'currentPage' => $paginator->getCurrentPage(),
                'pageCount' => $paginator->getPageCount(),
                'count' => $paginator->getCursor()->getCount(),
                'hasMorePages' => $paginator->hasMorePages(),
                'cursor' => $this->paginatorCursorFactory->toString($paginator->getCursor()),
            ],
        ]));
    }
}
