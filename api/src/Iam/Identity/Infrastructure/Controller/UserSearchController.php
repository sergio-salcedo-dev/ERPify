<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Controller;

use Erpify\Iam\Identity\Application\Query\SearchUsersQuery;
use Erpify\Iam\Identity\Application\UserSearcher;
use Erpify\Iam\Identity\Infrastructure\Http\UserResourceMapper;
use Erpify\Shared\Search\Application\Http\SearchQuery;
use Erpify\Shared\Search\Infrastructure\Http\SearchResponder;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * Read-only identity register surface, gated by `users.read`. Mounts under `/api/v1` (the
 * `api_v1_iam_identity` resource prefix) so the path resolves to `/api/v1/backoffice/users`. The literal
 * permission string is intentional: with a single action today there is no constants class (the same
 * choice as `auditTrail.read`); one lands when a second action arrives in a later story.
 */
#[Route('/backoffice/users', name: self::ROUTE_NAME, methods: ['GET'])]
#[IsGranted('users.read')]
final readonly class UserSearchController
{
    public const string ROUTE_NAME = 'backoffice_user_search';

    public function __construct(
        private UserSearcher $userSearcher,
        private UserResourceMapper $userResourceMapper,
        private SearchResponder $searchResponder,
    ) {
    }

    /**
     * @throws ExceptionInterface
     * @throws JsonException
     */
    public function __invoke(
        #[MapQueryString]
        SearchQuery $query = new SearchQuery(),
    ): Response {
        return $this->searchResponder->respond(
            $this->userResourceMapper->toListPage(
                $this->userSearcher->search(new SearchUsersQuery($query->toCriteria())),
            ),
            $query,
            self::ROUTE_NAME,
        );
    }
}
