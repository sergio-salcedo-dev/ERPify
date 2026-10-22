<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence\Fixtures;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-only route that leaves its transaction open, so the request boundary's containment is driven through a real
 * kernel. `?commit=1` takes the same lock and commits it, which is how a later request proves the connection it
 * inherits is usable.
 *
 * @internal
 */
final readonly class LeakTransactionController
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(Request $request): Response
    {
        LeaksATransaction::on($this->connection);

        if ($request->query->getBoolean('commit')) {
            $this->connection->commit();
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
