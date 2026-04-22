<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Health\Infrastructure\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use Erpify\Shared\Application\UseCase\Result;
use Erpify\Shared\Infrastructure\Http\Responder\ResponderInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HealthController
{
    public function __construct(
        private ResponderInterface $responder,
    ) {
    }

    #[Route('/health', name: 'backoffice_health', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->responder->respond(Result::ok([
            'status' => 'ok',
            'service' => 'Back office',
            'datetime' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ]));
    }
}
