<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Health\Infrastructure\Controller;

use DateTimeInterface;
use Erpify\Backoffice\Health\Application\CheckDatabaseHealth;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Http\Infrastructure\Responder\ResponderInterface;
use Erpify\Shared\Kernel\Application\Result;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DatabaseHealthController
{
    public function __construct(
        private ResponderInterface $responder,
        private CheckDatabaseHealth $checkDatabaseHealth,
        private Clock $clock,
    ) {
    }

    #[Route('/health/database', name: 'backoffice_health_database', methods: ['GET'])]
    public function __invoke(): Response
    {
        $available = $this->checkDatabaseHealth->run();

        return $this->responder->respond(Result::ok([
            'status' => $available ? 'ok' : 'error',
            'service' => 'Database',
            'datetime' => $this->clock->now()->format(DateTimeInterface::ATOM),
        ]));
    }
}
